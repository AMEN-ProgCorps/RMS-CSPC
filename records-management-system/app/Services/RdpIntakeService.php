<?php

namespace App\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shared RDP intake insert used by POST /rdp/api/intake.
 * DCS documents are unique per distributed office. DTS stays unique per document code.
 */
class RdpIntakeService
{
    /**
     * Stable intake code for a DCS registration.
     * Empty masterlist numbers use DCS-REQ-{id} so offices can still appraise.
     */
    public static function documentCodeForRequest(int $requestId, ?string $docNo = null): string
    {
        $docNo = trim((string) $docNo);
        if ($docNo !== '') {
            return $docNo;
        }

        return 'DCS-REQ-'.max(0, $requestId);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function accept(array $validated): array
    {
        $source = (string) $validated['source_subsystem'];
        $code = trim((string) $validated['document_code']);
        $validated['document_title'] = mb_substr(trim((string) $validated['document_title']), 0, 255);
        $target = trim((string) ($validated['target_office'] ?? ''));
        if ($source === 'DCS' && $target !== '') {
            $target = strtoupper($target);
        }

        $existing = self::findExisting($source, $code, $target);
        if (! $existing && $source === 'DCS') {
            $requestId = (int) (is_array($validated['metadata'] ?? null)
                ? ($validated['metadata']['dcs_request_id'] ?? 0)
                : 0);
            if ($requestId > 0 && $target !== '') {
                $existing = self::findExistingByRequest($requestId, $target);
            }
        }

        $landingUrl = $source === 'DCS'
            ? route('rdp.received-documents.dcs')
            : route('rdp.received-documents.dts');

        $description = $validated['description'] ?? null;
        $metadata = $validated['metadata'] ?? null;

        if ($existing) {
            if (($existing->status ?? '') === 'pending' && $source === 'DCS' && $target !== '') {
                DB::table('rdp_received_documents')->where('id', $existing->id)->update([
                    'document_code' => $code !== '' ? $code : $existing->document_code,
                    'document_title' => $validated['document_title'],
                    'description' => $description,
                    'origin_office' => $validated['origin_office'] ?? $existing->origin_office,
                    'date_received' => $validated['date_received'] ?? $existing->date_received,
                    'file_path' => $validated['file_path'] ?? $existing->file_path,
                    'file_name' => $validated['file_name'] ?? $existing->file_name,
                    'document_id_handler' => $validated['document_id_handler'] ?? $existing->document_id_handler,
                    'metadata' => $metadata !== null ? json_encode($metadata) : $existing->metadata,
                    'updated_at' => now(),
                ]);
                $existing = DB::table('rdp_received_documents')->where('id', $existing->id)->first();
            }

            return [
                'success' => true,
                'already_sent' => true,
                'message' => "Document '{$code}' is already in RDP intake"
                    .($target !== '' ? " for {$target}" : '')
                    .'.',
                'id' => $existing->id,
                'status' => $existing->status,
                'target_office' => $existing->target_office,
                'landing_url' => $landingUrl,
                'appraise_url' => self::appraiseUrl($existing),
            ];
        }

        $id = DB::table('rdp_received_documents')->insertGetId([
            'source_subsystem' => $source,
            'document_code' => $code,
            'document_title' => $validated['document_title'],
            'description' => $description,
            'origin_office' => $validated['origin_office'] ?? null,
            'target_office' => $target !== '' ? $target : null,
            'date_received' => $validated['date_received'] ?? now()->toDateString(),
            'file_path' => $validated['file_path'] ?? null,
            'file_name' => $validated['file_name'] ?? null,
            'document_id_handler' => $validated['document_id_handler'] ?? null,
            'status' => 'pending',
            'sent_by_user' => auth()->id(),
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('rdp_received_documents')->where('id', $id)->first();

        return [
            'success' => true,
            'already_sent' => false,
            'message' => "Document '{$code}' successfully sent to RDP!",
            'id' => $id,
            'status' => 'pending',
            'target_office' => $row->target_office ?? null,
            'landing_url' => $landingUrl,
            'appraise_url' => self::appraiseUrl($row),
        ];
    }

    public static function findExisting(string $source, string $code, string $target = ''): ?object
    {
        $query = DB::table('rdp_received_documents')
            ->where('source_subsystem', $source)
            ->where('document_code', $code);

        if ($source === 'DCS' && $target !== '') {
            $query->whereRaw('LOWER(target_office) = ?', [strtolower($target)]);
        }

        return $query->first();
    }

    public static function findExistingByRequest(int $requestId, string $targetOffice): ?object
    {
        if ($requestId < 1 || trim($targetOffice) === '' || ! DB::getSchemaBuilder()->hasTable('rdp_received_documents')) {
            return null;
        }
        if (! DB::getSchemaBuilder()->hasColumn('rdp_received_documents', 'metadata')) {
            return null;
        }

        try {
            return DB::table('rdp_received_documents')
                ->where('source_subsystem', 'DCS')
                ->whereRaw('LOWER(target_office) = ?', [strtolower(trim($targetOffice))])
                ->whereRaw("COALESCE(metadata->>'dcs_request_id', '') = ?", [(string) $requestId])
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function appraiseUrl(object $row): string
    {
        return route('rdp.add-records.inventory-and-appraisal', [
            'prefill_intake_id' => $row->id,
            'prefill_source' => $row->source_subsystem,
            'prefill_code' => $row->document_code,
            'prefill_title' => $row->document_title,
            'prefill_desc' => $row->description,
            'prefill_date' => $row->date_received,
            'prefill_doc_id' => $row->document_id_handler,
        ]);
    }

    /**
     * Pending DCS appraisal links for one office, keyed by document code and DCS-REQ-{id}.
     *
     * @param  list<string>  $documentCodes
     * @return array<string, string>
     */
    public static function pendingUrlsForOffice(?string $officeCode, array $documentCodes): array
    {
        $officeCode = strtoupper(trim((string) $officeCode));
        $codes = array_values(array_filter(array_map(
            fn ($code) => trim((string) $code),
            $documentCodes
        ), fn ($code) => $code !== ''));

        if ($officeCode === '' || ! DB::getSchemaBuilder()->hasTable('rdp_received_documents')) {
            return [];
        }

        $query = DB::table('rdp_received_documents')
            ->where('source_subsystem', 'DCS')
            ->where('status', 'pending')
            ->whereRaw('LOWER(target_office) = ?', [strtolower($officeCode)])
            ->orderByDesc('id');

        if ($codes !== []) {
            $query->where(function ($q) use ($codes) {
                $q->whereIn('document_code', $codes);
                foreach ($codes as $code) {
                    if (preg_match('/^DCS-REQ-(\d+)$/i', $code, $m)) {
                        $q->orWhereRaw("COALESCE(metadata->>'dcs_request_id', '') = ?", [$m[1]]);
                    }
                }
            });
        }

        $urls = [];
        foreach ($query->get() as $row) {
            $code = (string) $row->document_code;
            if (! isset($urls[$code])) {
                $urls[$code] = self::appraiseUrl($row);
            }
            $meta = is_string($row->metadata ?? null)
                ? (json_decode($row->metadata, true) ?: [])
                : (array) ($row->metadata ?? []);
            $reqId = (int) ($meta['dcs_request_id'] ?? 0);
            if ($reqId > 0) {
                $reqKey = self::documentCodeForRequest($reqId, '');
                if (! isset($urls[$reqKey])) {
                    $urls[$reqKey] = self::appraiseUrl($row);
                }
            }
        }

        return $urls;
    }

    /**
     * Appraisal links for received DCS copies. Creates a pending intake when RDP
     * was turned on after the office already marked the document received.
     *
     * @param  list<array{doc_no?: string, request_id?: int, doc_title?: string}>  $rows
     * @return array<string, string>
     */
    public static function appraisalUrlsForReceivedOfficeDocs(?string $officeCode, array $rows): array
    {
        $officeCode = strtoupper(trim((string) $officeCode));
        if ($officeCode === '' || $rows === [] || ! DB::getSchemaBuilder()->hasTable('rdp_received_documents')) {
            return [];
        }

        $codes = [];
        $byLookup = [];
        foreach ($rows as $row) {
            $requestId = (int) ($row['request_id'] ?? 0);
            $code = self::documentCodeForRequest($requestId, (string) ($row['doc_no'] ?? ''));
            if ($code === '' || $code === 'DCS-REQ-0') {
                continue;
            }
            $codes[] = $code;
            if (! isset($byLookup[$code])) {
                $byLookup[$code] = $row;
            }
            if ($requestId > 0) {
                $reqKey = self::documentCodeForRequest($requestId, '');
                $codes[] = $reqKey;
                if (! isset($byLookup[$reqKey])) {
                    $byLookup[$reqKey] = $row;
                }
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }

        $pending = self::pendingUrlsForOffice($officeCode, $codes);
        $urls = [];
        foreach ($byLookup as $code => $row) {
            $requestId = (int) ($row['request_id'] ?? 0);
            $reqKey = $requestId > 0 ? self::documentCodeForRequest($requestId, '') : '';
            $url = $pending[$code] ?? ($reqKey !== '' ? ($pending[$reqKey] ?? null) : null);
            if ($url) {
                $docNo = trim((string) ($row['doc_no'] ?? ''));
                if ($docNo !== '') {
                    $urls[$docNo] = $url;
                }
                if ($requestId > 0) {
                    $urls['request:'.$requestId] = $url;
                    $urls[$reqKey] = $url;
                }
            }
        }

        return $urls;
    }

    public static function ensurePendingForOfficeDocument(
        string $officeCode,
        int $requestId,
        string $docNo,
        string $title = ''
    ): ?string {
        $officeCode = strtoupper(trim($officeCode));
        $code = self::documentCodeForRequest($requestId, $docNo);
        if ($officeCode === '' || $requestId < 1) {
            return null;
        }

        $existing = self::findExisting('DCS', $code, $officeCode)
            ?: self::findExistingByRequest($requestId, $officeCode);
        if ($existing) {
            if (strtolower((string) ($existing->status ?? '')) === 'appraised') {
                return null;
            }

            return self::appraiseUrl($existing);
        }

        $result = self::accept([
            'source_subsystem' => 'DCS',
            'document_code' => $code,
            'document_title' => $title !== '' ? $title : $code,
            'description' => $title !== '' ? $title : $code,
            'origin_office' => null,
            'target_office' => $officeCode,
            'date_received' => now()->toDateString(),
            'file_path' => null,
            'file_name' => null,
            'document_id_handler' => null,
            'metadata' => ['dcs_request_id' => $requestId],
        ]);

        return is_string($result['appraise_url'] ?? null) ? $result['appraise_url'] : null;
    }

    public static function requestAlreadyForwarded(int $requestId): bool
    {
        if ($requestId < 1 || ! DB::getSchemaBuilder()->hasTable('rdp_received_documents')) {
            return false;
        }
        if (! DB::getSchemaBuilder()->hasColumn('rdp_received_documents', 'metadata')) {
            return false;
        }

        try {
            return DB::table('rdp_received_documents')
                ->where('source_subsystem', 'DCS')
                ->whereRaw("COALESCE(metadata->>'dcs_request_id', '') = ?", [(string) $requestId])
                ->whereRaw("COALESCE((metadata->>'dcs_forwarded')::text, '') IN ('true', '1')")
                ->exists();
        } catch (\Throwable $e) {
            return DB::table('rdp_received_documents')
                ->where('source_subsystem', 'DCS')
                ->whereRaw("COALESCE(metadata->>'dcs_request_id', '') = ?", [(string) $requestId])
                ->exists();
        }
    }

    public static function adminRecordExistsForRequest(int $requestId, string $officeOwn): bool
    {
        return self::adminRecordIdForRequest($requestId, $officeOwn) !== null;
    }

    public static function adminRecordIdForRequest(int $requestId, string $officeOwn): ?int
    {
        $officeOwn = strtoupper(trim($officeOwn));
        if ($requestId < 1 || $officeOwn === '' || ! DB::getSchemaBuilder()->hasTable('rdp_received_documents')) {
            return null;
        }

        try {
            $id = DB::table('rdp_received_documents')
                ->where('source_subsystem', 'DCS')
                ->whereRaw('LOWER(target_office) = ?', [strtolower($officeOwn)])
                ->whereRaw("COALESCE(metadata->>'dcs_request_id', '') = ?", [(string) $requestId])
                ->whereNotNull('appraised_record_id')
                ->orderByDesc('id')
                ->value('appraised_record_id');

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function rememberAdminRecord(int $requestId, string $officeOwn, int $recordId): void
    {
        $officeOwn = strtoupper(trim($officeOwn));
        if ($requestId < 1 || $officeOwn === '' || $recordId < 1) {
            return;
        }

        $code = self::documentCodeForRequest($requestId);
        $result = self::accept([
            'source_subsystem' => 'DCS',
            'document_code' => $code,
            'document_title' => $code,
            'description' => $code,
            'origin_office' => $officeOwn,
            'target_office' => $officeOwn,
            'date_received' => now()->toDateString(),
            'file_path' => null,
            'file_name' => null,
            'document_id_handler' => null,
            'metadata' => [
                'dcs_forwarded' => true,
                'dcs_request_id' => $requestId,
                'dcs_admin_record' => true,
            ],
        ]);

        if (! empty($result['id'])) {
            DB::table('rdp_received_documents')->where('id', (int) $result['id'])->update([
                'status' => 'appraised',
                'appraised_record_id' => $recordId,
                'updated_at' => now(),
            ]);
            if (class_exists(\App\Helpers\OfficeIntakeHelper::class)) {
                \App\Helpers\OfficeIntakeHelper::notifyPendingIntakeRegistrationAfterAppraisal($requestId);
            }
        }
    }

    /** Call after any rdp_received_documents row becomes appraised for a DCS request. */
    public static function afterDcsRequestAppraised(int $requestId): void
    {
        if ($requestId < 1) {
            return;
        }
        if (class_exists(\App\Helpers\OfficeIntakeHelper::class)) {
            \App\Helpers\OfficeIntakeHelper::notifyPendingIntakeRegistrationAfterAppraisal($requestId);
        }
    }

    public static function redirectToAdminAppraisal(Request $request, int $requestId, string $successMessage): ?RedirectResponse
    {
        if ((string) $request->input('open_appraisal') !== '1' || $requestId < 1) {
            return null;
        }
        if (self::requestAlreadyForwarded($requestId)) {
            return null;
        }

        $hasOffices = DB::table('dcs_distribution_offices as d')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'd.distribution_id')
            ->where('dist.request_id', $requestId)
            ->exists();
        if (! $hasOffices) {
            return null;
        }

        return redirect()
            ->route('rdp.add-records.inventory-and-appraisal', ['dcs_request_id' => $requestId])
            ->with('success', $successMessage);
    }
}
