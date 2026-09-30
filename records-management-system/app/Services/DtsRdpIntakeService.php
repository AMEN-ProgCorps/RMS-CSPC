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
