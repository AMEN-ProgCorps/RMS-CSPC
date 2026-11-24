<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DtsRoutingService
{
    public const BUILT_IN_ACTION_OPTIONS = [
        'For Approval',
        'For Endorsement',
        'For Recommendation',
        'For Revision',
        'For Signature',
        'To be Forwarded to',
    ];

    /**
     * Get action options query builder ordered with system built-in options first, followed by custom user/admin options.
     *
     * @param bool|null $canForward Clearance to include "To be Forwarded to"; defaults to checking auth user.
     * @return \Illuminate\Database\Query\Builder
     */
    public static function getActionOptionsQuery(?bool $canForward = null)
    {
        $canForward = $canForward ?? self::canForwardToOtherOffice();

        $query = DB::table('dts_action_options');
        if (!$canForward) {
            $query->where('option_name', '!=', 'To be Forwarded to');
        }

        $builtIns = self::BUILT_IN_ACTION_OPTIONS;
        $inList = "'" . implode("','", array_map('addslashes', $builtIns)) . "'";

        return $query->orderByRaw("CASE WHEN option_name IN ({$inList}) THEN 0 ELSE 1 END ASC, option_name ASC");
    }

    /**
     * Get action option names ordered with system built-in options first, followed by custom user/admin options.
     *
     * @param bool|null $canForward Clearance to include "To be Forwarded to"; defaults to checking auth user.
     * @return array<int, string>
     */
    public static function getActionOptions(?bool $canForward = null): array
    {
        if (!Schema::hasTable('dts_action_options')) {
            return self::BUILT_IN_ACTION_OPTIONS;
        }

        $options = self::getActionOptionsQuery($canForward)->pluck('option_name')->toArray();

        return !empty($options) ? $options : self::BUILT_IN_ACTION_OPTIONS;
    }

    /**
     * Check if a user has clearance to dynamically forward a transaction to an ad-hoc other office.
     *
     * Evaluates super admin (is_sadm) or specific can_dts_forward_to_other_office permission.
     *
     * @param \App\Models\User|null $user The user model to evaluate; defaults to authenticated user.
     * @return bool True if authorized to forward to other offices; false otherwise.
     */
    public static function canForwardToOtherOffice(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (!$user) {
            return false;
        }

        $perms = $user->permissions;
        if (!$perms) {
            return false;
        }

        return (bool) ($perms->is_sadm || ($perms->can_dts_forward_to_other_office ?? false));
    }

    /**
     * Retrieve available offices for dynamic forwarding, excluding placeholder codes and the current office.
     *
     * Optionally prepends the virtual Office Hub [HUB] broadcast option for multi-recipient dispatches.
     *
     * @param string|null $excludeOfficeCode Office code to exclude (typically sender's current station).
     * @param bool $includeHub Whether to include the '[HUB]' virtual broadcasting option.
     * @return \Illuminate\Support\Collection<int, object> Collection of office records.
     */
    public static function getAvailableTargetOffices(?string $excludeOfficeCode = null, bool $includeHub = true): Collection
    {
        $table = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        $query = DB::table($table)
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]']);

        if (!empty($excludeOfficeCode)) {
            $query->where('office_code', '!=', $excludeOfficeCode);
        }

        $offices = $query->orderBy('office_name', 'asc')->get();

        if ($includeHub) {
            $hubObj = (object) [
                'office_code' => '[HUB]',
                'office_name' => 'Office Hub [HUB] (Broadcast to Multiple)',
                'cluster' => null,
                'is_active' => true,
            ];
            $offices->prepend($hubObj);
        }

        return $offices;
    }

    /**
     * Dynamically insert an ad-hoc office into the active transaction sequence flow and route to it.
     * Supports routing to an individual office or multi-mode broadcasting via Office Hub [HUB].
     *
     * Clones flow into custom FLOW-CUSTOM-* if necessary, recalculates step durations, shifts sequences,
     * logs the forwarding audit event, and delivers notification alerts.
     *
     * @param string $transactionId Unique transaction identifier.
     * @param string $targetOfficeCode Target office code, or '[HUB]' for multi-office broadcasting.
     * @param string|null $notes Forwarding notes or routing instructions.
     * @param int|null $userId User ID performing the action; defaults to auth()->id().
     * @param array<int, string> $hubOffices List of recipient office codes when target is '[HUB]'.
     * @return array Routing result payload containing transaction details and destination name.
     * @throws \Exception When user lacks permissions, working hours are restricted, or transaction is invalid.
     */
    public static function forwardToOtherOffice(
        string $transactionId,
        string $targetOfficeCode,
        ?string $notes = '',
        ?int $userId = null,
        array $hubOffices = []
    ): array {
        $userId = $userId ?? auth()->id();
        $user = $userId ? User::find($userId) : auth()->user();

        if (!self::canForwardToOtherOffice($user)) {
            throw new \Exception('Unauthorized: You do not have clearance to forward transactions to other offices.');
        }

        if (!DtsWorkingHoursService::canProcessStationAction()) {
            throw new \Exception(DtsWorkingHoursService::getStationActionRestrictionNotice());
        }

        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $userOfficeCode = $user?->details?->office?->office_code 
            ?? DocumentStorageService::resolveOfficeCode($user);

        $isHub = ($targetOfficeCode === '[HUB]');

        if ($isHub) {
            if (in_array('ALL', $hubOffices)) {
                $allSystemOffices = DB::table($officeTable)
                    ->where('is_active', true)
                    ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', $userOfficeCode])
                    ->orderByRaw("COALESCE(cluster, 'ZZZZ') ASC")
                    ->orderBy('office_name', 'asc')
                    ->pluck('office_code')
                    ->toArray();
                $hubOffices = $allSystemOffices;
            } else {
                $hubOffices = array_values(array_filter($hubOffices, fn($c) => $c !== $userOfficeCode && !in_array($c, ['ORIGIN', '[H]', '[HUB]'])));
                if (!empty($hubOffices)) {
                    $ordered = DB::table($officeTable)
                        ->whereIn('office_code', $hubOffices)
                        ->orderByRaw("COALESCE(cluster, 'ZZZZ') ASC")
                        ->orderBy('office_name', 'asc')
                        ->pluck('office_code')
                        ->toArray();
                    if (!empty($ordered)) {
                        $hubOffices = $ordered;
                    }
                }
            }

            if (empty($hubOffices)) {
                throw new \Exception('Please select at least one receiving office for the Office Hub broadcast.');
            }

            $targetOffice = (object) [
                'office_code' => '[HUB]',
                'office_name' => 'Office Hub [HUB]',
            ];
        } else {
            $targetOffice = DB::table($officeTable)
                ->where('office_code', $targetOfficeCode)
                ->where('is_active', true)
                ->first();

            if (!$targetOffice || in_array($targetOfficeCode, ['ORIGIN', '[H]', '[HUB]'])) {
                throw new \Exception("Invalid target office code [{$targetOfficeCode}].");
            }

            if ($targetOfficeCode === $userOfficeCode) {
                throw new \Exception('Target office cannot be your own office.');
            }
        }

        return DB::transaction(function () use (
            $transactionId,
            $targetOfficeCode,
            $targetOffice,
            $notes,
            $userId,
            $user,
            $userOfficeCode,
            $officeTable,
            $isHub,
            $hubOffices
        ) {
            $trans = DB::table('dts_transactions as dt')
                ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
                ->where('dt.transaction_id', $transactionId)
                ->select(
                    'dt.transaction_id',
                    'dt.status',
                    'dt.sequence',
                    'dt.current_office',
                    'dtd.control_number',
                    'dtd.subject',
                    'dtd.originated_from',
                    'dtd.transaction_flow'
                )
                ->lockForUpdate()
                ->first();

            if (!$trans) {
                throw new \Exception('Transaction not found.');
            }

            if ($trans->current_office !== $userOfficeCode && !($user?->permissions?->is_sadm ?? false)) {
                throw new \Exception('Transaction is not currently at your office.');
            }

            $flow = DB::table('dts_transaction_flow')
                ->where('flow_code', $trans->transaction_flow)
                ->first();

            if (!$flow) {
                throw new \Exception('Transaction flow configuration not found.');
            }

            $flowId = $flow->id;
            $flowCode = $flow->flow_code;

            // Fork into custom flow if currently using a shared template flow
            if (!str_starts_with($flowCode, 'FLOW-CUSTOM-')) {
                $newFlowCode = 'FLOW-CUSTOM-' . strtoupper(Str::random(10));
                $newFlowId = (DB::table('dts_transaction_flow')->max('id') ?? 1000) + 1;

                DB::table('dts_transaction_flow')->insert([
                    'flow_code' => $newFlowCode,
                    'flow_name' => 'Flow for ' . $trans->control_number . ' (' . $newFlowCode . ')',
                    'id' => $newFlowId,
                    'is_active' => 1,
                    'added_by' => $userId ?? 1,
                    'date_added' => now(),
                    'flow_use' => 'none',
                    'flow_for' => 'system',
                    'referenced_flow' => $flow->referenced_flow ?? $flow->flow_name,
                ]);

                $existingSteps = DB::table('dts_sequence_list')
                    ->where('control_id', $flowId)
                    ->orderBy('sequence_ranking', 'asc')
                    ->get();

                foreach ($existingSteps as $s) {
                    DB::table('dts_sequence_list')->insert([
                        'control_id' => $newFlowId,
                        'sequence_ranking' => $s->sequence_ranking,
                        'office_code' => $s->office_code,
                        'date_in' => $s->date_in,
                        'date_out' => $s->date_out,
                        'action_needed' => $s->action_needed,
                        'note' => $s->note,
                        'total_time_received' => $s->total_time_received ?? null,
                        'total_time_completed' => $s->total_time_completed,
                        'scanned_id' => $s->scanned_id ?? false,
                        'account_received' => $s->account_received ?? null,
                        'account_forwarded' => $s->account_forwarded ?? null,
                    ]);
                }

                DB::table('dts_transaction_details')
                    ->where('id', $transactionId)
                    ->update(['transaction_flow' => $newFlowCode]);

                $flowId = $newFlowId;
                $flowCode = $newFlowCode;
            }

            // Calculate duration spent at current step
            $currentStep = DB::table('dts_sequence_list')
                ->where('control_id', $flowId)
                ->where('sequence_ranking', $trans->sequence)
                ->first();

            $duration = null;
            if ($currentStep && $currentStep->date_in) {
                $dateIn = Carbon::parse($currentStep->date_in);
                $diff = $dateIn->diff(now());
                $parts = [];
                if ($diff->d > 0) $parts[] = $diff->d . ' ' . Str::plural('day', $diff->d);
                if ($diff->h > 0) $parts[] = $diff->h . ' ' . Str::plural('hour', $diff->h);
                if ($diff->i > 0) $parts[] = $diff->i . ' ' . Str::plural('minute', $diff->i);
                if (empty($parts)) $parts[] = 'less than a minute';
                $duration = implode(' ', $parts);
            }

            // Close current step in sequence list
            DB::table('dts_sequence_list')
                ->where('control_id', $flowId)
                ->where('sequence_ranking', $trans->sequence)
                ->update([
                    'date_out' => now(),
                    'account_forwarded' => $userId,
                    'action_needed' => 'To be Forwarded to',
                    'note' => $notes,
                    'total_time_completed' => $duration,
                ]);

            // Shift all subsequent steps down by +1
            DB::table('dts_sequence_list')
                ->where('control_id', $flowId)
                ->where('sequence_ranking', '>', $trans->sequence)
                ->orderBy('sequence_ranking', 'desc')
                ->increment('sequence_ranking', 1);

            // Insert new step right after current sequence
            $nextSequenceNum = $trans->sequence + 1;
            DB::table('dts_sequence_list')->insert([
                'control_id' => $flowId,
                'sequence_ranking' => $nextSequenceNum,
                'office_code' => $targetOfficeCode,
                'date_in' => null,
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_received' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ]);

            $logTable = Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
            $userOfficeName = DB::table($officeTable)->where('office_code', $userOfficeCode)->value('office_name') ?: $userOfficeCode;

            // Close current open log for user office
            DB::table($logTable)
                ->where('transaction_id', $transactionId)
                ->where('office_code', $userOfficeCode)
                ->whereNull('date_out')
                ->update([
                    'date_out' => now(),
                    'type' => 'forwarded',
                    'notes' => $notes ?: ('Forwarded to ' . $targetOffice->office_name),
                    'performed_by' => $userId,
                ]);

            if ($isHub) {
                // Multi-mode Hub broadcast dispatch
                $assignOfficesId = (DB::table('dts_copy_filled_transaction')->max('assign_offices_id') ?? 1000) + 1;
                $copyFilledId = DB::table('dts_copy_filled_transaction')->insertGetId([
                    'control_num' => $trans->control_number,
                    'total_office' => count($hubOffices),
                    'assign_offices_id' => $assignOfficesId,
                    'data_created' => now(),
                    'date_modified' => now(),
                ]);

                foreach ($hubOffices as $hOff) {
                    DB::table('dts_copy_filled_to_office')->insert([
                        'control_id' => $assignOfficesId,
                        'office_code' => $hOff,
                    ]);
                }

                $primaryHubOffice = $hubOffices[0];
                DB::table('dts_transactions')
                    ->where('transaction_id', $transactionId)
                    ->update([
                        'current_office' => $primaryHubOffice,
                        'sequence' => $nextSequenceNum,
                        'status' => 'ongoing',
                    ]);

                DB::table('dts_transaction_details')
                    ->where('id', $transactionId)
                    ->update([
                        'current_office_hold' => $primaryHubOffice,
                        'action_needed' => 'To be Forwarded to',
                        'copy_filled_id' => $copyFilledId,
                    ]);

                DB::table($logTable)->insert([
                    'transaction_id' => $transactionId,
                    'office_code' => $primaryHubOffice,
                    'type' => 'forwarded',
                    'date_in' => null,
                    'date_out' => null,
                    'notes' => 'Dispatched via Office Hub [HUB] (Primary)',
                    'performed_by' => $userId,
                ]);

                DtsNotificationService::notifyWaitingToBeReceived($primaryHubOffice, $trans->control_number, $transactionId);

                // Parse control number parts for child suffixes
                $cParts = explode('-', $trans->control_number);
                $typePrefix = $cParts[0] ?? 'DOC';
                $seqBase = end($cParts);

                // Copy prior logs to replicate complete routing history for children
                $priorLogs = DB::table($logTable)
                    ->where('transaction_id', $transactionId)
                    ->whereNotNull('date_in')
                    ->orderBy('id', 'asc')
                    ->get();

                // Offices 2..N spawn child transactions with hyphenated suffixes (-1, -2, ...)
                for ($i = 1; $i < count($hubOffices); $i++) {
                    $childOffice = $hubOffices[$i];
                    $childSeq = $seqBase . '-' . $i;
                    $childControlNumber = $trans->control_number . '-' . $i;
                    $childQrCode = DtsQrCodeService::generate($typePrefix, $childSeq);
                    $childTransId = 'TRANS-' . strtoupper(Str::random(10));

                    // Insert child into dts_transactions
                    DB::table('dts_transactions')->insert([
                        'transaction_id' => $childTransId,
                        'enable_notif' => 1,
                        'trans_type' => 'memorandom',
                        'doc_dir' => null,
                        'qr_code' => $childQrCode,
                        'current_office' => $childOffice,
                        'status' => 'ongoing',
                        'sequence' => $nextSequenceNum,
                    ]);

                    // Insert child into dts_transaction_details
                    DB::table('dts_transaction_details')->insert([
                        'id' => $childTransId,
                        'type' => 'memorandom',
                        'created_by' => $userId,
                        'originated_from' => $trans->originated_from ?? $userOfficeCode,
                        'requestor_id' => null,
                        'source_office' => null,
                        'subject' => $trans->subject ?? '',
                        'classification' => null,
                        'action_needed' => 'To be Forwarded to',
                        'current_office_hold' => $childOffice,
                        'status' => 'ongoing',
                        'document_password' => null,
                        'email_access' => null,
                        'transaction_flow' => $flowCode,
                        'is_active' => 1,
                        'date_created' => now(),
                        'control_number' => $childControlNumber,
                        'copy_filled_id' => $copyFilledId,
                    ]);

                    // Replicate prior logs for this child transaction
                    foreach ($priorLogs as $pLog) {
                        DB::table($logTable)->insert([
                            'transaction_id' => $childTransId,
                            'office_code' => $pLog->office_code,
                            'type' => $pLog->type,
                            'date_in' => $pLog->date_in,
                            'date_out' => $pLog->date_out,
                            'notes' => $pLog->notes,
                            'performed_by' => $pLog->performed_by,
                        ]);
                    }

                    // Pending log for child recipient office
                    DB::table($logTable)->insert([
                        'transaction_id' => $childTransId,
                        'office_code' => $childOffice,
                        'type' => 'forwarded',
                        'date_in' => null,
                        'date_out' => null,
                        'notes' => 'Dispatched via Office Hub [HUB] (Child Branch #' . $i . ')',
                        'performed_by' => $userId,
                    ]);

                    DtsNotificationService::notifyWaitingToBeReceived($childOffice, $childControlNumber, $childTransId);
                }

                $userFirstName = $user?->details?->first_name 
                    ?? $user?->first_name 
                    ?? $user?->username 
                    ?? 'User';

                DtsNotificationService::notifyForwarded($userOfficeCode, $userFirstName, $trans->control_number, $transactionId);

                return [
                    'success' => true,
                    'transaction_id' => $transactionId,
                    'control_number' => $trans->control_number,
                    'target_office_code' => '[HUB]',
                    'target_office_name' => 'Office Hub (' . count($hubOffices) . ' offices)',
                ];
            } else {
                // Update transaction pointer to new single office
                DB::table('dts_transactions')
                    ->where('transaction_id', $transactionId)
                    ->update([
                        'current_office' => $targetOfficeCode,
                        'sequence' => $nextSequenceNum,
                        'status' => 'ongoing',
                    ]);

                DB::table('dts_transaction_details')
                    ->where('id', $transactionId)
                    ->update([
                        'current_office_hold' => $targetOfficeCode,
                        'action_needed' => 'To be Forwarded to',
                    ]);

                // Create incoming forwarded log for the target office
                DB::table($logTable)->insert([
                    'transaction_id' => $transactionId,
                    'office_code' => $targetOfficeCode,
                    'type' => 'forwarded',
                    'date_in' => null,
                    'date_out' => null,
                    'notes' => 'Forwarded from ' . $userOfficeName . ($notes ? ': ' . $notes : ''),
                    'performed_by' => $userId,
                ]);

                $userFirstName = $user?->details?->first_name 
                    ?? $user?->first_name 
                    ?? $user?->username 
                    ?? 'User';

                DtsNotificationService::notifyForwarded($userOfficeCode, $userFirstName, $trans->control_number, $transactionId);
                DtsNotificationService::notifyWaitingToBeReceived($targetOfficeCode, $trans->control_number, $transactionId);

                return [
                    'success' => true,
                    'transaction_id' => $transactionId,
                    'control_number' => $trans->control_number,
                    'target_office_code' => $targetOfficeCode,
                    'target_office_name' => $targetOffice->office_name,
                ];
            }
        });
    }
}
