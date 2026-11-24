<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=======================================================\n";
echo " SEEDING ALMOST-COMPLETE DTS TRANSACTIONS FOR RDP HANDOFF\n";
echo "=======================================================\n\n";

$officeCode = 'Dev';
$userId = 1;

// -------------------------------------------------------------------
// 1. TRANSACTION 1: Internal, Final Station at Dev (Almost Complete)
// -------------------------------------------------------------------
$tx1Id = 'TRANS-ALMOST-DONE-01';
$tx1Cn = 'INT-2026-11-AC001';
$tx1Subject = 'Annual Strategic Development Plan & Performance Targets (2026-2027)';
$tx1Qr = 'ALMST-TEST-QR-0001';
$tx1FlowCode = 'FLOW-DEFENSE-AC01';

$tx2Id = 'TRANS-ALMOST-DONE-02';
$tx2Cn = 'EXT-2026-11-AC002';
$tx2Subject = 'CHED Institutional Development Grant Liquidation & Audit Certification';
$tx2Qr = 'ALMST-TEST-QR-0002';
$tx2FlowCode = 'FLOW-DEFENSE-AC02';

// Cleanup old flows if any
$oldFlowIds = DB::table('dts_transaction_flow')->whereIn('flow_code', [$tx1FlowCode, $tx2FlowCode])->pluck('id');
if ($oldFlowIds->isNotEmpty()) {
    DB::table('dts_sequence_list')->whereIn('control_id', $oldFlowIds)->delete();
    DB::table('dts_transaction_flow')->whereIn('id', $oldFlowIds)->delete();
}

$nextFlowId = (DB::table('dts_transaction_flow')->max('id') ?? 500) + 1;
$flow1Id = $nextFlowId;
$flow2Id = $nextFlowId + 1;

// Flow & Sequence 1
DB::table('dts_transaction_flow')->insert([
    'id' => $flow1Id,
    'flow_code' => $tx1FlowCode,
    'flow_name' => 'Strategic Plan Approval Flow',
    'referenced_flow' => 'DTS Flow',
    'contin_rdp_id' => 1, // ACKNOWLEDGEMENT RECEIPTS / Plan
    'added_by' => $userId,
    'date_added' => Carbon::now()->subDays(1),
    'is_active' => 1,
    'flow_use' => 'internal',
    'flow_for' => 'office',
]);

DB::table('dts_sequence_list')->insert([
    [
        'control_id' => $flow1Id,
        'sequence_ranking' => 1,
        'office_code' => 'President',
        'date_in' => Carbon::now()->subDays(1),
        'date_out' => Carbon::now()->subDays(1),
        'action_needed' => 'For Review & Routing',
        'note' => 'Initiated by President Office',
        'total_time_completed' => '5 minutes',
        'scanned_id' => 1,
        'account_received' => $userId,
        'account_forwarded' => $userId,
        'total_time_received' => null,
    ],
    [
        'control_id' => $flow1Id,
        'sequence_ranking' => 2,
        'office_code' => $officeCode, // Final destination: Dev
        'date_in' => Carbon::now()->subHours(2),
        'date_out' => null, // Still held at Dev!
        'action_needed' => 'For Final Action & Archiving',
        'note' => null,
        'total_time_completed' => null,
        'scanned_id' => 1,
        'account_received' => $userId,
        'account_forwarded' => null,
        'total_time_received' => null,
    ],
]);

DB::table('dts_qr_code')->updateOrInsert(
    ['code_id' => $tx1Qr],
    ['qr_status' => 'used', 'created_at' => Carbon::now()]
);

DB::table('dts_transactions')->updateOrInsert(
    ['transaction_id' => $tx1Id],
    [
        'enable_notif' => 1,
        'trans_type' => 'internal',
        'qr_code' => $tx1Qr,
        'current_office' => $officeCode,
        'status' => 'ongoing',
        'sequence' => 2, // At step 2 of 2 (FINAL STEP!)
    ]
);

DB::table('dts_transaction_details')->updateOrInsert(
    ['id' => $tx1Id],
    [
        'type' => 'internal',
        'created_by' => $userId,
        'originated_from' => 'President',
        'current_office_hold' => $officeCode,
        'status' => 'ongoing',
        'document_password' => 'handoff123',
        'email_access' => 1,
        'transaction_flow' => $tx1FlowCode,
        'is_active' => 1,
        'date_created' => Carbon::now()->subDays(1),
        'control_number' => $tx1Cn,
        'subject' => $tx1Subject,
        'action_needed' => 'APPROVED',
        'requestor_id' => $userId,
    ]
);

DB::table('dts_transaction_logs')->where('transaction_id', $tx1Id)->delete();
DB::table('dts_transaction_logs')->insert([
    [
        'transaction_id' => $tx1Id,
        'office_code' => 'President',
        'type' => 'forwarded',
        'date_in' => Carbon::now()->subDays(1),
        'date_out' => Carbon::now()->subDays(1),
        'notes' => 'Forwarded to Dev for final evaluation and disposition.',
        'performed_by' => $userId,
    ],
    [
        'transaction_id' => $tx1Id,
        'office_code' => $officeCode,
        'type' => 'received',
        'date_in' => Carbon::now()->subHours(2),
        'date_out' => null,
        'notes' => 'Received at Dev office. Ready for completion.',
        'performed_by' => $userId,
    ],
]);

echo "  -> {$tx1Cn} created. Next Office is: End of Flow (Complete)!\n\n";

// -------------------------------------------------------------------
// 2. TRANSACTION 2: External, Final Station at Dev (Almost Complete)
// -------------------------------------------------------------------
$tx2Id = 'TRANS-ALMOST-DONE-02';
$tx2Cn = 'EXT-2026-11-AC002';
$tx2Subject = 'CHED Institutional Development Grant Liquidation & Audit Certification';
$tx2Qr = 'ALMST-TEST-QR-0002';
$tx2FlowCode = 'FLOW-DEFENSE-AC02';

echo "2. Creating Transaction {$tx2Cn}...\n";

DB::table('dts_transaction_flow')->insert([
    'id' => $flow2Id,
    'flow_code' => $tx2FlowCode,
    'flow_name' => 'CHED Liquidation Audit Flow',
    'referenced_flow' => 'DTS Flow',
    'contin_rdp_id' => 9, // CERTIFICATIONS
    'added_by' => $userId,
    'date_added' => Carbon::now()->subHours(12),
    'is_active' => 1,
    'flow_use' => 'external',
    'flow_for' => 'office',
]);

DB::table('dts_sequence_list')->insert([
    [
        'control_id' => $flow2Id,
        'sequence_ranking' => 1,
        'office_code' => 'COA',
        'date_in' => Carbon::now()->subHours(12),
        'date_out' => Carbon::now()->subHours(12),
        'action_needed' => 'For Endorsement',
        'note' => 'Transmitted from Commission on Audit',
        'total_time_completed' => '10 minutes',
        'scanned_id' => 1,
        'account_received' => $userId,
        'account_forwarded' => $userId,
        'total_time_received' => null,
    ],
    [
        'control_id' => $flow2Id,
        'sequence_ranking' => 2,
        'office_code' => $officeCode, // Final destination: Dev
        'date_in' => Carbon::now()->subHours(1),
        'date_out' => null,
        'action_needed' => 'For Approval',
        'note' => null,
        'total_time_completed' => null,
        'scanned_id' => 1,
        'account_received' => $userId,
        'account_forwarded' => null,
        'total_time_received' => null,
    ],
]);

DB::table('dts_qr_code')->updateOrInsert(
    ['code_id' => $tx2Qr],
    ['qr_status' => 'used', 'created_at' => Carbon::now()]
);

DB::table('dts_transactions')->updateOrInsert(
    ['transaction_id' => $tx2Id],
    [
        'enable_notif' => 1,
        'trans_type' => 'external',
        'qr_code' => $tx2Qr,
        'current_office' => $officeCode,
        'status' => 'ongoing',
        'sequence' => 2,
    ]
);

DB::table('dts_transaction_details')->updateOrInsert(
    ['id' => $tx2Id],
    [
        'type' => 'external',
        'created_by' => $userId,
        'originated_from' => 'COA',
        'current_office_hold' => $officeCode,
        'status' => 'ongoing',
        'document_password' => 'handoff123',
        'email_access' => 1,
        'transaction_flow' => $tx2FlowCode,
        'is_active' => 1,
        'date_created' => Carbon::now()->subHours(12),
        'control_number' => $tx2Cn,
        'subject' => $tx2Subject,
        'action_needed' => 'For Approval',
        'requestor_id' => $userId,
    ]
);

DB::table('dts_transaction_logs')->where('transaction_id', $tx2Id)->delete();
DB::table('dts_transaction_logs')->insert([
    [
        'transaction_id' => $tx2Id,
        'office_code' => 'COA',
        'type' => 'forwarded',
        'date_in' => Carbon::now()->subHours(12),
        'date_out' => Carbon::now()->subHours(12),
        'notes' => 'Forwarded from COA to Dev for completion and RDP archiving.',
        'performed_by' => $userId,
    ],
    [
        'transaction_id' => $tx2Id,
        'office_code' => $officeCode,
        'type' => 'received',
        'date_in' => Carbon::now()->subHours(1),
        'date_out' => null,
        'notes' => 'Received at Dev. Ready for completion.',
        'performed_by' => $userId,
    ],
]);

echo "  -> {$tx2Cn} created. Next Office is: End of Flow (Complete)!\n\n";

// -------------------------------------------------------------------
// 3. TRANSACTION 3: Already Converted into RDP (To see final RDP state)
// -------------------------------------------------------------------
$tx3Id = 'TRANS-CONVERTED-01';
$tx3Cn = 'INT-2026-11-RDP999';
$tx3Subject = 'Official Memoranda & Policy Directives on Document Digitization (Archived)';
$tx3Qr = 'ALMST-TEST-QR-0003';

echo "3. Creating Completed & Converted Transaction {$tx3Cn} in RDP...\n";

DB::table('dts_qr_code')->updateOrInsert(
    ['code_id' => $tx3Qr],
    ['qr_status' => 'used', 'created_at' => Carbon::now()]
);

DB::table('dts_transactions')->updateOrInsert(
    ['transaction_id' => $tx3Id],
    [
        'enable_notif' => 1,
        'trans_type' => 'internal',
        'qr_code' => $tx3Qr,
        'current_office' => $officeCode,
        'status' => 'completed',
        'sequence' => 2,
    ]
);

DB::table('dts_transaction_details')->updateOrInsert(
    ['id' => $tx3Id],
    [
        'type' => 'internal',
        'created_by' => $userId,
        'originated_from' => 'President',
        'current_office_hold' => $officeCode,
        'status' => 'completed',
        'transaction_flow' => $tx1FlowCode,
        'is_active' => 1,
        'date_created' => Carbon::now()->subDays(3),
        'control_number' => $tx3Cn,
        'subject' => $tx3Subject,
        'action_needed' => 'Finished',
        'requestor_id' => $userId,
    ]
);

// Use DtsRdpIntakeService to properly insert into NAP Form 1 & RDP Received Documents
$user = \App\Models\User::find($userId);
$paperMediumId = DB::table('rdp_recorded_value')->whereRaw('LOWER(medium_name) = ?', ['paper'])->value('id') ?? 1;
\App\Services\DtsRdpIntakeService::recordDirectToNap([
    'transaction_id' => $tx3Id,
    'control_number' => $tx3Cn,
    'subject' => $tx3Subject,
    'description' => $tx3Subject,
    'record_series_id' => 1,
    'date_covered' => Carbon::now()->format('Y-m-d'),
    'volume' => '1 Folder',
    'records_location' => 'Records Section Archive Shelf A-1',
    'records_medium' => $paperMediumId,
    'restriction' => 'Restricted',
    'frequence_use' => 'Annually',
    'utility_values' => [1, 2], // Administrative, Archival
    'duplicate_offices' => [$officeCode],
], $user);

echo "  -> {$tx3Cn} recorded into RDP Received Documents and NAP Form 1 via DtsRdpIntakeService!\n\n";

echo "=======================================================\n";
echo " SUCCESS! The almost-complete data is ready for testing.\n";
echo "=======================================================\n";
