<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * TEMPORARY DTS -> RDP bridge.
 *
 * When a DTS transaction is fully completed (status = 'completed'), it is handed
 * to the RDP "Received Documents" inbox through the existing rdp/api/intake
 * endpoint, so the finished document shows up in RDP without a manual import.
 *
 * The endpoint's own closure is invoked in-process - the same trick the legacy
 * /rdp/intake/send alias uses - because rdp/api/* sits behind session auth and
 * cannot be reached with a plain server-side HTTP request (no token guard).
 */
class DtsRdpIntakeService
{
    /**
     * Push a fully-completed DTS transaction into rdp_received_documents.
     *
     * Safe to call after ANY DTS action: it no-ops unless the transaction is
     * actually 'completed', and the intake endpoint is idempotent
     * (source_subsystem + document_code) so repeats return already_sent.
     *
     * Never throws - a failed hand-off is only logged, so it can never break or
     * roll back the completion itself.
     *
     * @param  string|int|null  $transactionId  dts_transactions.transaction_id
     * @return array|null  decoded intake response (success / already_sent / id), or null when skipped/failed
     */
    public static function recordCompleted($transactionId): ?array
    {
        try {
            if (empty($transactionId) || !Schema::hasTable('dts_transaction_details')) {
                return null;
            }

            $trans = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where('dt.transaction_id', $transactionId)
                ->select('dt.*', 'dtd.control_number', 'dtd.subject', 'dtd.originated_from', 'dtd.transaction_flow')
                ->first();

            if (!$trans || ($trans->status ?? null) !== 'completed' || empty($trans->control_number)) {
                return null;
            }

            // Attach the registered document file when one exists for this control number.
            $sysDoc = null;
            if (Schema::hasTable('sys_document_data')) {
                $sysDoc = DB::table('sys_document_data')
                    ->where('document_id', $trans->control_number)
                    ->orWhere('document_id', 'like', 'DTS%' . $trans->control_number . '%')
                    ->orWhere('document_name', 'like', '%' . $trans->control_number . '%')
                    ->first();
            }

            // Record series continuity for the completed transaction (contin_rdp_id on
            // dts_transaction_flow). A flow left at "Server default" (NULL) falls back
            // to the record series configured in System Settings, so changing that
            // setting immediately affects every flow still on Server default.
            $recordSeriesId = null;
            if (!empty($trans->transaction_flow)
                && Schema::hasTable('dts_transaction_flow')
                && Schema::hasColumn('dts_transaction_flow', 'contin_rdp_id')) {
                $recordSeriesId = DB::table('dts_transaction_flow')
                    ->where('flow_code', $trans->transaction_flow)
                    ->value('contin_rdp_id');
            }

            if (empty($recordSeriesId)) {
                $settingsTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
                if (Schema::hasTable($settingsTable)) {
                    $recordSeriesId = DB::table($settingsTable)
                        ->where('key', 'rdp_server_default_record_series_id')
                        ->value('value');
                }
            }

            $recordSeriesId = ($recordSeriesId !== null && $recordSeriesId !== '')
                ? (int) $recordSeriesId
                : null;

            $seriesTypeId = null;
            if ($recordSeriesId !== null && Schema::hasTable('rdp_record_series')) {
                $series = DB::table('rdp_record_series')
                    ->where('id', $recordSeriesId)
                    ->first(['id', 'series_type']);

                if ($series) {
                    $seriesTypeId = $series->series_type;
                } else {
                    // Referenced series no longer exists — fall back to no series.
                    $recordSeriesId = null;
                }
            }

            $response = self::callIntakeEndpoint([
                'source_subsystem'    => 'DTS',
                'document_code'       => $trans->control_number,
                'document_title'      => $trans->subject ?: 'Untitled Document',
                'description'         => 'Completed DTS Transaction #' . $trans->control_number,
                'origin_office'       => $trans->originated_from ?: null,
                'target_office'       => $trans->current_office ?: null,
                'date_received'       => now()->toDateString(),
                'file_path'           => $sysDoc?->document_path,
                'file_name'           => $sysDoc?->document_name,
                'document_id_handler' => $sysDoc?->document_id,
                'metadata'            => [
                    'transaction_id'            => (string) $trans->transaction_id,
                    'flow_code'                 => $trans->transaction_flow ?? null,
                    'record_series_id'          => $recordSeriesId,
                    'rdp_record_series_type_id' => $seriesTypeId,
                    'completed_at'              => now()->toIso8601String(),
                    'completed_by'              => auth()->user()?->username ?: 'System',
                    'trigger'                   => 'dts_transaction_completed',
                ],
            ]);

            Log::info('[DTS->RDP] Completed transaction recorded in RDP intake', [
                'transaction_id' => (string) $trans->transaction_id,
                'control_number' => $trans->control_number,
                'rdp_id'         => $response['id'] ?? null,
                'already_sent'   => $response['already_sent'] ?? false,
            ]);

            return $response;
        } catch (\Throwable $e) {
            Log::warning('[DTS->RDP] Failed to record completed transaction in RDP intake', [
                'transaction_id' => (string) $transactionId,
                'error'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Prepare initial RDP hand-off payload for a DTS transaction.
     * Pre-populates:
     *  - Record Series (from flow contin_rdp_id or server default)
     *  - Description = Subject
     *  - Records Medium = 'Paper'
     *  - Restriction = 'Restricted'
     *  - Duplicate Offices = Copy Furnished offices (or user's office if empty)
     *  - Date Covered = today
     */
    public static function prepareHandoffPayload($transactionId, $user = null): ?array
    {
        if (empty($transactionId) || !Schema::hasTable('dts_transaction_details')) {
            return null;
        }

        $trans = null;
        if (is_numeric($transactionId)) {
            $numId = (int)$transactionId;
            $trans = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where(function ($q) use ($numId) {
                    $q->where('dt.transaction_id', $numId)
                      ->orWhere('dtd.id', $numId)
                      ->orWhere('dt.id', $numId);
                })
                ->select('dt.*', 'dtd.id as details_id', 'dtd.control_number', 'dtd.subject', 'dtd.originated_from', 'dtd.transaction_flow', 'dtd.date_created')
                ->first();
        }

        if (!$trans) {
            $strId = trim((string)$transactionId);
            $trans = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where(function ($q) use ($strId) {
                    $q->where('dt.transaction_id', $strId)
                      ->orWhere('dtd.id', $strId)
                      ->orWhere('dtd.control_number', $strId)
                      ->orWhere('dt.qr_code', $strId);
                })
                ->select('dt.*', 'dtd.id as details_id', 'dtd.control_number', 'dtd.subject', 'dtd.originated_from', 'dtd.transaction_flow', 'dtd.date_created')
                ->first();
        }

        // Direct fallback on dts_transaction_details alone if join missed
        if (!$trans && Schema::hasTable('dts_transaction_details')) {
            $dtd = DB::table('dts_transaction_details')
                ->where('id', $transactionId)
                ->orWhere('control_number', trim((string)$transactionId))
                ->first();
            if ($dtd) {
                $dt = DB::table('dts_transactions')->where('transaction_id', $dtd->id)->first();
                $trans = (object) array_merge((array)($dt ?? []), (array)$dtd, [
                    'details_id' => $dtd->id,
                    'transaction_id' => $dtd->id,
                ]);
            }
        }

        if (!$trans || empty($trans->control_number)) {
            return null;
        }

        $user = $user ?: auth()->user();
        $userOfficeCode = $user?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode($user);

        // Resolve attached file if exists
        $sysDoc = null;
        if (Schema::hasTable('sys_document_data')) {
            $sysDoc = DB::table('sys_document_data')
                ->where('document_id', $trans->control_number)
                ->orWhere('document_id', 'like', 'DTS%' . $trans->control_number . '%')
                ->orWhere('document_name', 'like', '%' . $trans->control_number . '%')
                ->first();
        }

        // Resolve Record Series (flow contin_rdp_id -> referenced flow -> server default -> first series)
        $recordSeriesId = null;
        if (!empty($trans->transaction_flow)
            && Schema::hasTable('dts_transaction_flow')
            && Schema::hasColumn('dts_transaction_flow', 'contin_rdp_id')) {
            $flow = DB::table('dts_transaction_flow')
                ->where('flow_code', $trans->transaction_flow)
                ->first();
            if ($flow) {
                $recordSeriesId = $flow->contin_rdp_id;
                if (empty($recordSeriesId) && !empty($flow->referenced_flow)) {
                    if (preg_match('/^REF-(?:CUSTOM|PREDEFINED)-(\d+)$/', $flow->referenced_flow, $mMatches)) {
                        $recordSeriesId = DB::table('dts_transaction_flow')->where('id', $mMatches[1])->value('contin_rdp_id');
                    }
                }
            }
            if (empty($recordSeriesId) && is_numeric($trans->transaction_flow)) {
                $recordSeriesId = DB::table('dts_transaction_flow')
                    ->where('id', (int)$trans->transaction_flow)
                    ->value('contin_rdp_id');
            }
        }

        if (empty($recordSeriesId)) {
            $settingsTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
            if (Schema::hasTable($settingsTable)) {
                $recordSeriesId = DB::table($settingsTable)
                    ->where('key', 'rdp_server_default_record_series_id')
                    ->value('value');
            }
        }

        if (empty($recordSeriesId) && Schema::hasTable('rdp_record_series')) {
            $recordSeriesId = DB::table('rdp_record_series')->orderBy('id', 'asc')->value('id');
        }

        $recordSeriesId = ($recordSeriesId !== null && $recordSeriesId !== '') ? (int) $recordSeriesId : null;

        $seriesDetails = null;
        if ($recordSeriesId && Schema::hasTable('rdp_record_series')) {
            $series = DB::table('rdp_record_series')->where('id', $recordSeriesId)->first();
            if ($series) {
                $retentionText = 'Standard Retention';
                if ($series->retention_period && Schema::hasTable('rdp_retention_period')) {
                    $rp = DB::table('rdp_retention_period')->where('id', $series->retention_period)->first();
                    if ($rp) {
                        $retentionText = "Active: " . ($rp->active_period ?? 'N/A') . " | Storage: " . ($rp->storage_period ?? 'N/A') . " | Total: " . ($rp->total_period ?? 'N/A');
                    }
                }
                $seriesDetails = [
                    'id'             => $series->id,
                    'series_title'   => $series->series_title,
                    'item_number'    => $series->item_number ?? '',
                    'retention_text' => $retentionText,
                ];
            } else {
                $recordSeriesId = null;
            }
        }

        // Resolve duplicate copies from Copy Furnished
        $duplicateOffices = [];
        if (Schema::hasTable('dts_copy_filled_transaction')) {
            $cf = DB::table('dts_copy_filled_transaction')->where('control_num', $trans->control_number)->first();
            if ($cf && !empty($cf->assign_offices_id) && Schema::hasTable('dts_copy_filled_to_office')) {
                $duplicateOffices = DB::table('dts_copy_filled_to_office')
                    ->where('control_id', $cf->assign_offices_id)
                    ->pluck('office_code')
                    ->filter()
                    ->values()
                    ->toArray();
            }
        }

        // When Copy Furnished is empty, record user's office as the sole duplicate
        if (empty($duplicateOffices) && !empty($userOfficeCode)) {
            $duplicateOffices = [$userOfficeCode];
        }

        // Default medium: Paper
        $paperMediumId = null;
        if (Schema::hasTable('rdp_recorded_value')) {
            $paperMediumId = DB::table('rdp_recorded_value')->whereRaw('LOWER(medium_name) = ?', ['paper'])->value('id')
                ?? DB::table('rdp_recorded_value')->where('is_active', true)->value('id');
        }

        return [
            'transaction_id'      => (string) $trans->transaction_id,
            'control_number'      => $trans->control_number,
            'subject'             => $trans->subject ?: 'Untitled Document',
            'description'         => $trans->subject ?: 'Completed DTS Transaction #' . $trans->control_number,
            'origin_office'       => $trans->originated_from ?: $userOfficeCode,
            'target_office'       => $trans->current_office ?: null,
            'flow_code'           => $trans->transaction_flow,
            'date_covered'        => now()->format('Y-m-d'),
            'record_series_id'    => $recordSeriesId,
            'series_details'      => $seriesDetails,
            'records_medium'      => $paperMediumId,
            'restriction'         => 'Restricted',
            'duplicate_offices'   => $duplicateOffices,
            'file_path'           => $sysDoc?->document_path,
            'file_name'           => $sysDoc?->document_name,
            'document_id_handler' => $sysDoc?->document_id,
        ];
    }

    /**
     * Record completed DTS transaction directly into RDP NAP Form 1 (rdp_record).
     */
    public static function recordDirectToNap(array $data, $user = null): array
    {
        try {
            $user = $user ?: auth()->user();
            $userOfficeCode = $user?->details?->office?->office_code 
                ?? \App\Services\DocumentStorageService::resolveOfficeCode($user);

            // Defensively ensure control_number is resolved
            $controlNumber = $data['control_number'] ?? null;
            if (empty($controlNumber) && !empty($data['transaction_id'])) {
                if (is_numeric($data['transaction_id'])) {
                    $controlNumber = DB::table('dts_transaction_details')->where('id', (int)$data['transaction_id'])->value('control_number');
                } else {
                    $controlNumber = trim((string)$data['transaction_id']);
                }
            }
            if (empty($controlNumber)) {
                $controlNumber = 'DTS-' . now()->format('YmdHis');
            }
            $data['control_number'] = $controlNumber;

            if (empty($data['record_series_id'])) {
                throw new \InvalidArgumentException('Record Series is required to record directly to NAP Form 1.');
            }

            $series = DB::table('rdp_record_series')->where('id', $data['record_series_id'])->first();
            if (!$series) {
                throw new \InvalidArgumentException('Specified Record Series not found.');
            }

            return DB::transaction(function () use ($data, $user, $userOfficeCode, $series) {
                // 1. Resolve or clone retention period
                $periodId = $series->retention_period ?? null;
                if (!$periodId && Schema::hasTable('rdp_retention_period')) {
                    $periodId = DB::table('rdp_retention_period')->insertGetId([
                        'active_period'  => 'Permanent',
                        'storage_period' => null,
                        'total_period'   => 'Permanent',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                // 2. Format volume: amount + unit or provided volume
                $volume = trim($data['volume'] ?? '');
                if (empty($volume) && !empty($data['volume_amount'])) {
                    $volume = trim($data['volume_amount'] . ' ' . ($data['volume_unit'] ?? 'FOLDER'));
                }

                // 3. Medium ID
                $mediumId = !empty($data['records_medium']) ? (int) $data['records_medium'] : null;
                if (!$mediumId && Schema::hasTable('rdp_recorded_value')) {
                    $mediumId = DB::table('rdp_recorded_value')->whereRaw('LOWER(medium_name) = ?', ['paper'])->value('id');
                }

                // 4. Time value (Permanent if retention says permanent, else Temporary)
                $timeValue = $data['time_value'] ?? 'T';
                if ($series && Schema::hasTable('rdp_retention_period') && $series->retention_period) {
                    $rp = DB::table('rdp_retention_period')->where('id', $series->retention_period)->first();
                    if ($rp && (str_contains(strtolower($rp->total_period ?? ''), 'perm') || str_contains(strtolower($rp->active_period ?? ''), 'perm'))) {
                        $timeValue = 'P';
                    }
                }

                // 5. Insert into rdp_record with is_draft = false (directly onto NAP Form 1)
                $recordId = DB::table('rdp_record')->insertGetId([
                    'record_series_id'      => (int) $data['record_series_id'],
                    'description'           => mb_strtoupper(trim($data['description'] ?? $data['subject'] ?? '')),
                    'period_id'             => $periodId,
                    'volume'                => mb_strtoupper($volume ?: '1 FOLDER'),
                    'records_location'      => mb_strtoupper(trim($data['records_location'] ?? 'RECORDS ARCHIVE')),
                    'restriction'           => $data['restriction'] ?? 'Restricted',
                    'records_medium'        => $mediumId,
                    'time_value'            => $timeValue,
                    'frequence_use'         => $data['frequence_use'] ?? null,
                    'user_own'              => $user?->id,
                    'office_own'            => $userOfficeCode,
                    'upload_doc_id_handler' => $data['document_id_handler'] ?? null,
                    'is_draft'              => false, // directly on NAP Form 1!
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);

                DB::table('rdp_record')->where('id', $recordId)->update([
                    'utility_value'  => $recordId,
                    'duplication_id' => $recordId,
                ]);

                // 6. Utility values
                if (!empty($data['utility_values']) && is_array($data['utility_values']) && Schema::hasTable('rdp_utility_manager')) {
                    foreach ($data['utility_values'] as $uId) {
                        DB::table('rdp_utility_manager')->insert([
                            'record_holder'  => $recordId,
                            'utility_medium' => (int) $uId,
                            'is_active'      => true,
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                    }
                }

                // 7. Duplicate offices
                $dupOffices = $data['duplicate_offices'] ?? [];
                if (empty($dupOffices) && !empty($userOfficeCode)) {
                    $dupOffices = [$userOfficeCode];
                }
                if (Schema::hasTable('rdp_duplication_section')) {
                    foreach ($dupOffices as $dOffice) {
                        if (!empty($dOffice)) {
                            DB::table('rdp_duplication_section')->insert([
                                'dup_id_manager' => $recordId,
                                'office_code'    => $dOffice,
                                'created_at'     => now(),
                                'updated_at'     => now(),
                            ]);
                        }
                    }
                }

                // 8. Period covered
                if (!empty($data['date_covered']) && Schema::hasTable('rdp_period_covered')) {
                    DB::table('rdp_period_covered')->insert([
                        'period_owner' => $recordId,
                        'date_covered' => $data['date_covered'],
                        'created_at'   => now(),
                        'modified_at'  => now(),
                    ]);
                }

                // 9. Mark or insert in rdp_received_documents with status 'appraised'
                $existing = DB::table('rdp_received_documents')
                    ->where('source_subsystem', 'DTS')
                    ->where('document_code', $data['control_number'])
                    ->first();

                $metaPayload = array_merge($data, [
                    'record_id'    => $recordId,
                    'completed_at' => now()->toIso8601String(),
                    'completed_by' => $user?->username ?? 'User',
                    'flow_mode'    => 'direct_nap_form_1',
                ]);

                if ($existing) {
                    DB::table('rdp_received_documents')->where('id', $existing->id)->update([
                        'status'              => 'appraised',
                        'appraised_record_id' => $recordId,
                        'metadata'            => json_encode($metaPayload),
                        'updated_at'          => now(),
                    ]);
                } else {
                    DB::table('rdp_received_documents')->insert([
                        'source_subsystem'    => 'DTS',
                        'document_code'       => $data['control_number'],
                        'document_title'      => $data['subject'] ?? $data['description'] ?? 'Untitled Document',
                        'description'         => $data['description'] ?? null,
                        'origin_office'       => $data['origin_office'] ?? $userOfficeCode,
                        'target_office'       => $data['target_office'] ?? null,
                        'date_received'       => $data['date_covered'] ?? now()->toDateString(),
                        'file_path'           => $data['file_path'] ?? null,
                        'file_name'           => $data['file_name'] ?? null,
                        'document_id_handler' => $data['document_id_handler'] ?? null,
                        'status'              => 'appraised',
                        'appraised_record_id' => $recordId,
                        'sent_by_user'        => $user?->id,
                        'metadata'            => json_encode($metaPayload),
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                }

                Log::info('[DTS->RDP] Transaction directly converted to NAP Form 1', [
                    'control_number' => $data['control_number'],
                    'record_id'      => $recordId,
                ]);

                return [
                    'success'   => true,
                    'record_id' => $recordId,
                    'message'   => "Successfully recorded directly to RDP NAP Form 1!",
                ];
            });
        } catch (\Throwable $e) {
            Log::error('[DTS->RDP] Direct NAP Form 1 conversion failed: ' . $e->getMessage(), [
                'data' => $data,
            ]);
            return [
                'success' => false,
                'message' => 'Failed to record to NAP Form 1: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Record incomplete or deferred DTS transaction to RDP Received Documents queue.
     * Accessible on /rdp/received-documents/dts for later appraisal.
     */
    public static function recordIncompleteToIntake(array $data, $user = null): array
    {
        try {
            $user = $user ?: auth()->user();
            $userOfficeCode = $user?->details?->office?->office_code 
                ?? \App\Services\DocumentStorageService::resolveOfficeCode($user);

            // Defensively ensure control_number is resolved
            $controlNumber = $data['control_number'] ?? null;
            if (empty($controlNumber) && !empty($data['transaction_id'])) {
                if (is_numeric($data['transaction_id'])) {
                    $controlNumber = DB::table('dts_transaction_details')->where('id', (int)$data['transaction_id'])->value('control_number');
                } else {
                    $controlNumber = trim((string)$data['transaction_id']);
                }
            }
            if (empty($controlNumber)) {
                $controlNumber = 'DTS-' . now()->format('YmdHis');
            }
            $data['control_number'] = $controlNumber;

            $existing = DB::table('rdp_received_documents')
                ->where('source_subsystem', 'DTS')
                ->where('document_code', $data['control_number'])
                ->first();

            $rawVolume = trim((string) ($data['volume'] ?? ''));
            $volAmount = trim((string) ($data['volume_amount'] ?? ''));
            if (empty($volAmount) || !preg_match('/\d/', $rawVolume)) {
                $rawVolume = null;
                $volAmount = null;
                $volUnit = null;
            } else {
                $volUnit = $data['volume_unit'] ?? null;
            }

            $metaPayload = [
                'transaction_id'    => (string) ($data['transaction_id'] ?? ''),
                'flow_code'         => $data['flow_code'] ?? null,
                'record_series_id'  => !empty($data['record_series_id']) ? (int) $data['record_series_id'] : null,
                'volume'            => $rawVolume,
                'volume_amount'     => $volAmount,
                'volume_unit'       => $volUnit,
                'records_location'  => $data['records_location'] ?? null,
                'records_medium'    => !empty($data['records_medium']) ? (int) $data['records_medium'] : null,
                'restriction'       => $data['restriction'] ?? 'Restricted',
                'frequence_use'     => $data['frequence_use'] ?? null,
                'duplicate_offices' => $data['duplicate_offices'] ?? (!empty($userOfficeCode) ? [$userOfficeCode] : []),
                'utility_values'    => $data['utility_values'] ?? [],
                'date_covered'      => $data['date_covered'] ?? null,
                'completed_at'      => now()->toIso8601String(),
                'completed_by'      => $user?->username ?: 'System',
                'trigger'           => 'dts_incomplete_handoff',
            ];

            if ($existing) {
                DB::table('rdp_received_documents')->where('id', $existing->id)->update([
                    'document_title' => $data['subject'] ?? $existing->document_title,
                    'description'    => $data['description'] ?? $existing->description,
                    'metadata'       => json_encode($metaPayload),
                    'updated_at'     => now(),
                ]);
                $intakeId = $existing->id;
            } else {
                $intakeId = DB::table('rdp_received_documents')->insertGetId([
                    'source_subsystem'    => 'DTS',
                    'document_code'       => $data['control_number'],
                    'document_title'      => $data['subject'] ?? $data['description'] ?? 'Untitled Document',
                    'description'         => $data['description'] ?? ('Completed DTS Transaction #' . $data['control_number']),
                    'origin_office'       => $data['origin_office'] ?? $userOfficeCode,
                    'target_office'       => $data['target_office'] ?? null,
                    'date_received'       => $data['date_covered'] ?? now()->toDateString(),
                    'file_path'           => $data['file_path'] ?? null,
                    'file_name'           => $data['file_name'] ?? null,
                    'document_id_handler' => $data['document_id_handler'] ?? null,
                    'status'              => 'pending',
                    'sent_by_user'        => $user?->id,
                    'metadata'            => json_encode($metaPayload),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            return [
                'success'   => true,
                'intake_id' => $intakeId,
                'message'   => "Transaction queued in RDP Received Documents for later appraisal.",
            ];
        } catch (\Throwable $e) {
            Log::warning('[DTS->RDP] Incomplete intake record failed: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to save to Received Documents: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Only fully-completed DTS transactions may be imported into RDP.
     *
     * dts_transactions.status is the authoritative flag: every DTS list/query
     * reads it, and it is the only table re-open resets back to 'ongoing'
     * (dts_transaction_details.status is never reset, so it can be stale).
     *
     * @param  string  $controlNumber  dts_transaction_details.control_number
     */
    public static function isCompletedDts(string $controlNumber): bool
    {
        $controlNumber = trim($controlNumber);

        if ($controlNumber === '' || !Schema::hasTable('dts_transaction_details')) {
            return false;
        }

        $transactionId = DB::table('dts_transaction_details')
            ->where('control_number', $controlNumber)
            ->value('id');

        if (!$transactionId) {
            return false;
        }

        return DB::table('dts_transactions')
            ->where('transaction_id', $transactionId)
            ->value('status') === 'completed';
    }

    /**
     * Run the rdp/api/intake handler in-process with the given payload.
     *
     * @return array decoded JSON response
     *
     * @throws \Throwable when the route/handler is missing or validation fails
     */
    private static function callIntakeEndpoint(array $payload): array
    {
        $route = Route::getRoutes()->getByName('rdp.api.intake');
        if (!$route) {
            throw new \RuntimeException('Route [rdp.api.intake] is not registered.');
        }

        $uses = $route->getAction('uses');
        if (!$uses) {
            throw new \RuntimeException('Route [rdp.api.intake] has no handler.');
        }

        $request = Request::create(route('rdp.api.intake'), 'POST', $payload, [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response = app()->call($uses, ['request' => $request]);

        $content = is_object($response) && method_exists($response, 'getContent')
            ? $response->getContent()
            : (string) $response;

        return json_decode($content, true) ?: [];
    }
}
