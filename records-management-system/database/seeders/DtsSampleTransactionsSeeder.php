<?php

namespace Database\Seeders;

use App\Services\DtsWorkingHoursService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DtsSampleTransactionsSeeder extends Seeder
{
    /**
     * Seed randomized 3–6 station DTS transactions across all 4 types
     * with a mix of early, midway, almost-done, and completed states.
     */
    public function run(): void
    {
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accountTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $accountDetailsTable = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        $activeOffices = DB::table($officeTable)
            ->where('is_active', true)
            ->whereNotIn('office_code', ['ORIGIN', '[H]', '[HUB]', 'OFFICE TESTING'])
            ->pluck('office_code')
            ->values()
            ->all();

        if (count($activeOffices) < 5) {
            $this->command?->error('Not enough active offices to generate 3–6 station sequences.');
            return;
        }

        $allAccountIds = DB::table($accountTable)->pluck('id')->values()->all();
        if (empty($allAccountIds)) {
            $this->command?->error('No accounts found in database.');
            return;
        }
        $fallbackAccountId = $allAccountIds[0];

        $officeAccountMap = DB::table($accountDetailsTable . ' as ad')
            ->join($officeTable . ' as o', 'o.id', '=', 'ad.office_id')
            ->pluck('ad.account_id', 'o.office_code')
            ->all();

        $sourceOffices = Schema::hasTable('dts_source_office')
            ? DB::table('dts_source_office')->where('is_active', true)->pluck('s_office_code')->values()->all()
            : [];

        $requestorIds = Schema::hasTable('dts_requestor_history')
            ? DB::table('dts_requestor_history')->pluck('id')->values()->all()
            : [];

        $typeConfigs = [
            'internal' => [
                'prefix' => 'INT',
                'flow_use' => 'internal',
                'action' => 'For Review and Endorsement',
                'classification' => 'Administrative',
                'subjects' => [
                    'Request for Approval of Faculty Seminar Attendance',
                    'Endorsement of Quarterly Office Supplies Requisition',
                    'Submission of Updated Campus Inventory Report',
                    'Request for IT Equipment Maintenance and Upgrade',
                ],
            ],
            'external' => [
                'prefix' => 'EXT',
                'flow_use' => 'external',
                'action' => 'For Evaluation and Compliance',
                'classification' => 'External Communication',
                'subjects' => [
                    'CHED Regional Memorandum on Curriculum Alignment',
                    'DBM Budget Utilization Review Guidelines',
                    'LGU Joint Outreach Program Coordination Letter',
                    'External Audit Compliance and Documentation Request',
                ],
            ],
            'others' => [
                'prefix' => 'APL',
                'flow_use' => 'application',
                'action' => 'For Consideration and Approval',
                'classification' => 'Application',
                'subjects' => [
                    'Application for Administrative Officer II Position',
                    'Application for Faculty Study Leave with Pay',
                    'Application for Laboratory Assistant Renewal',
                    'Application for Research Grant Dissemination Support',
                ],
            ],
            'memorandom' => [
                'prefix' => 'ISS',
                'flow_use' => 'issuances',
                'action' => 'For Action / Dissemination',
                'classification' => 'Memorandum',
                'subjects' => [
                    'Office Memorandum on Year-End Records Archiving',
                    'Campus Advisory on Working Hours Dispatch Protocol',
                    'Directive on Digital Document Tracking Compliance',
                    'Memorandum on Annual Property and Equipment Audit',
                ],
            ],
        ];

        $states = ['early', 'midway', 'almost_done', 'completed'];
        $hasPendingCol = Schema::hasColumn('dts_transactions', 'pending_working_hours_dispatch');
        $hasTotalTimeReceived = Schema::hasColumn('dts_sequence_list', 'total_time_received');
        $logTable = Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';

        $createdCount = 0;

        foreach ($typeConfigs as $transType => $cfg) {
            foreach ($states as $idx => $state) {
                $totalSteps = random_int(3, 6);

                // Ensure at least 2 per type originate from Buhi so they are immediately visible under Buhi Campus
                if ($idx < 2 && in_array('Buhi', $activeOffices, true)) {
                    $originOffice = 'Buhi';
                } else {
                    $originOffice = $activeOffices[array_rand($activeOffices)];
                }

                $candidateIntermediates = array_values(array_filter(
                    $activeOffices,
                    fn (string $code) => $code !== $originOffice
                ));
                shuffle($candidateIntermediates);
                $middleOffices = array_slice($candidateIntermediates, 0, $totalSteps - 2);

                // Sequence stored in dts_sequence_list: ORIGIN -> middle... -> ORIGIN
                $storedSequence = array_merge(['ORIGIN'], $middleOffices, ['ORIGIN']);
                $resolvedSequence = array_merge([$originOffice], $middleOffices, [$originOffice]);

                $currentSeq = match ($state) {
                    'early' => 2,
                    'midway' => max(2, (int) ceil($totalSteps / 2)),
                    'almost_done' => $totalSteps,
                    'completed' => $totalSteps,
                };

                $txStatus = $state === 'completed' ? 'completed' : 'ongoing';
                $currentOffice = $resolvedSequence[$currentSeq - 1];

                $transactionId = 'TRANS-' . strtoupper(Str::random(10));
                $controlNumber = $cfg['prefix'] . '-2026-11-' . Str::random(6);
                $flowCode = 'FLOW-CUSTOM-' . strtoupper(Str::random(10));
                $qrCode = 'QR-' . strtoupper(Str::random(10));

                $creatorAccountId = $officeAccountMap[$originOffice] ?? $fallbackAccountId;
                $requestorId = !empty($requestorIds) ? $requestorIds[array_rand($requestorIds)] : null;
                $sourceOffice = ($transType === 'external' && !empty($sourceOffices))
                    ? $sourceOffices[array_rand($sourceOffices)]
                    : null;
                $applyToOffice = ($transType === 'others')
                    ? $resolvedSequence[1]
                    : null;

                $baseTime = Carbon::now()->subDays(random_int(1, 10))->setTime(random_int(8, 15), random_int(0, 59));

                DB::transaction(function () use (
                    $transType,
                    $cfg,
                    $idx,
                    $state,
                    $totalSteps,
                    $originOffice,
                    $storedSequence,
                    $resolvedSequence,
                    $currentSeq,
                    $txStatus,
                    $currentOffice,
                    $transactionId,
                    $controlNumber,
                    $flowCode,
                    $qrCode,
                    $creatorAccountId,
                    $fallbackAccountId,
                    $officeAccountMap,
                    $requestorId,
                    $sourceOffice,
                    $applyToOffice,
                    $baseTime,
                    $hasPendingCol,
                    $hasTotalTimeReceived,
                    $logTable
                ) {
                    $flowId = (DB::table('dts_transaction_flow')->max('id') ?? 0) + 1;
                    DB::table('dts_transaction_flow')->insert([
                        'id' => $flowId,
                        'flow_code' => $flowCode,
                        'flow_name' => 'Flow for ' . $controlNumber . ' (' . $flowCode . ')',
                        'added_by' => $creatorAccountId,
                        'date_added' => $baseTime->copy(),
                        'flow_use' => $cfg['flow_use'],
                        'is_active' => true,
                        'flow_for' => 'system',
                        'referenced_flow' => null,
                        'contin_rdp_id' => null,
                    ]);

                    $txRow = [
                        'transaction_id' => $transactionId,
                        'enable_notif' => 1,
                        'trans_type' => $transType,
                        'doc_dir' => null,
                        'qr_code' => $qrCode,
                        'current_office' => $currentOffice,
                        'status' => $txStatus,
                        'sequence' => $currentSeq,
                    ];

                    if ($hasPendingCol) {
                        $txRow['pending_working_hours_dispatch'] = false;
                    }

                    DB::table('dts_qr_code')->insert([
                        'code_id' => $qrCode,
                        'qr_status' => 'used',
                        'created_at' => $baseTime->copy(),
                    ]);

                    DB::table('dts_transactions')->insert($txRow);

                    DB::table('dts_transaction_details')->insert([
                        'id' => $transactionId,
                        'type' => $transType,
                        'created_by' => $creatorAccountId,
                        'originated_from' => $originOffice,
                        'requestor_id' => $requestorId,
                        'source_office' => $sourceOffice,
                        'apply_to_office' => $applyToOffice,
                        'subject' => $cfg['subjects'][$idx % count($cfg['subjects'])],
                        'classification' => $cfg['classification'],
                        'action_needed' => $cfg['action'],
                        'current_office_hold' => $currentOffice,
                        'status' => $txStatus,
                        'document_password' => null,
                        'email_access' => null,
                        'transaction_flow' => $flowCode,
                        'is_active' => 1,
                        'date_created' => $baseTime->copy(),
                        'control_number' => $controlNumber,
                        'copy_filled_id' => null,
                    ]);

                    $cursorTime = $baseTime->copy();
                    $prevOutTime = null;

                    foreach ($storedSequence as $rankIdx => $storedCode) {
                        $rank = $rankIdx + 1;
                        $resolvedCode = $resolvedSequence[$rankIdx];
                        $stationAccountId = $officeAccountMap[$resolvedCode] ?? $fallbackAccountId;

                        $dateIn = null;
                        $dateOut = null;
                        $accReceived = null;
                        $accForwarded = null;
                        $actionTaken = null;
                        $note = null;
                        $totalReceived = null;
                        $totalCompleted = null;

                        if ($rank < $currentSeq || $state === 'completed') {
                            // Completed station
                            if ($rank === 1) {
                                $dateIn = $cursorTime->copy();
                                $totalReceived = '-';
                            } else {
                                $cursorTime = ($prevOutTime ?? $cursorTime)->copy()->addMinutes(random_int(15, 90));
                                $dateIn = $cursorTime->copy();
                                $totalReceived = DtsWorkingHoursService::calculateDurationString($prevOutTime, $dateIn);
                            }

                            $cursorTime = $dateIn->copy()->addMinutes(random_int(20, 180));
                            $dateOut = $cursorTime->copy();
                            $prevOutTime = $dateOut->copy();

                            $accReceived = $stationAccountId;
                            $accForwarded = $stationAccountId;
                            $actionTaken = $rank === 1 ? 'Created' : ($rank === $totalSteps ? 'Finished' : 'Forwarded');
                            $note = $rank === 1
                                ? 'Created ' . $transType . ' transaction'
                                : ($rank === $totalSteps ? 'Completed transaction at final station' : 'Processed and forwarded to next station');
                            $totalCompleted = DtsWorkingHoursService::calculateDurationString($dateIn, $dateOut);

                            DB::table($logTable)->insert([
                                'transaction_id' => $transactionId,
                                'office_code' => $resolvedCode,
                                'type' => $rank === $totalSteps ? 'completed' : 'forwarded',
                                'date_in' => $dateIn,
                                'date_out' => $dateOut,
                                'notes' => $note,
                                'performed_by' => $stationAccountId,
                            ]);
                        } elseif ($rank === $currentSeq) {
                            if ($state === 'early') {
                                // In transit to Step 2 (not yet received)
                                $dateIn = null;
                                $dateOut = null;
                            } else {
                                // Received at current station, pending forward/finalization
                                $cursorTime = ($prevOutTime ?? $cursorTime)->copy()->addMinutes(random_int(15, 90));
                                $dateIn = $cursorTime->copy();
                                $accReceived = $stationAccountId;
                                $actionTaken = 'Received';
                                $note = 'Currently being processed at ' . $resolvedCode;
                                $totalReceived = $prevOutTime
                                    ? DtsWorkingHoursService::calculateDurationString($prevOutTime, $dateIn)
                                    : '-';

                                DB::table($logTable)->insert([
                                    'transaction_id' => $transactionId,
                                    'office_code' => $resolvedCode,
                                    'type' => 'received',
                                    'date_in' => $dateIn,
                                    'date_out' => null,
                                    'notes' => $note,
                                    'performed_by' => $stationAccountId,
                                ]);
                            }
                        }

                        $seqRow = [
                            'control_id' => $flowId,
                            'sequence_ranking' => $rank,
                            'office_code' => $storedCode,
                            'date_in' => $dateIn,
                            'date_out' => $dateOut,
                            'action_needed' => $actionTaken,
                            'note' => $note,
                            'total_time_completed' => $totalCompleted,
                            'account_received' => $accReceived,
                            'account_forwarded' => $accForwarded,
                        ];

                        if ($hasTotalTimeReceived) {
                            $seqRow['total_time_received'] = $totalReceived;
                        }

                        DB::table('dts_sequence_list')->insert($seqRow);
                    }
                });

                $createdCount++;
                $this->command?->info(sprintf(
                    '[%s] %s | %-9s | step %d/%d | %s',
                    $transType,
                    $controlNumber,
                    $txStatus,
                    $currentSeq,
                    $totalSteps,
                    implode(' -> ', $resolvedSequence)
                ));
            }
        }

        $this->command?->info("Seeded {$createdCount} sample DTS transactions across all 4 types.");
    }
}
