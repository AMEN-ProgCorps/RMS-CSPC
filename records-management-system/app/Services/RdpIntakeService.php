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

        $landingUrl = $source === 'DCS'
            ? route('rdp.received-documents.dcs')
            : route('rdp.received-documents.dts');

        $description = $validated['description'] ?? null;
        $metadata = $validated['metadata'] ?? null;

        if ($existing) {
            if (($existing->status ?? '') === 'pending' && $source === 'DCS' && $target !== '') {
                DB::table('rdp_received_documents')->where('id', $existing->id)->update([
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
                    . ($target !== '' ? " for {$target}" : '')
                    . '.',
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
     * Pending DCS appraisal links for one office, keyed by document code.
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

        if ($officeCode === '' || $codes === [] || ! DB::getSchemaBuilder()->hasTable('rdp_received_documents')) {
            return [];
        }

        $rows = DB::table('rdp_received_documents')
            ->where('source_subsystem', 'DCS')
            ->where('status', 'pending')
            ->whereRaw('LOWER(target_office) = ?', [strtolower($officeCode)])
            ->whereIn('document_code', $codes)
            ->orderByDesc('id')
            ->get();

        $urls = [];
        foreach ($rows as $row) {
            $code = (string) $row->document_code;
            if (! isset($urls[$code])) {
                $urls[$code] = self::appraiseUrl($row);
            }
        }

        return $urls;
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
                ->exists();
        } catch (\Throwable $e) {
            return false;
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
