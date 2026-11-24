<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

echo "=============================================\n";
echo " GENERATING DEFENSE TEST DATA (5 NOTIF + 5 URGENT)\n";
echo "=============================================\n\n";

$officeCode = 'Dev';
$userId = 1;

// -------------------------------------------------------------
// 1. GENERATE 5 NORMAL NOTIFICATIONS (DTS, DCS, RDP)
// -------------------------------------------------------------
echo "1. Creating 5 Normal Notifications...\n";

// Remove previous test notifications for clean state
$oldNotifs = DB::table('sys_notif_content')
    ->where('content', 'LIKE', '%[DEFENSE-TEST]%')
    ->pluck('id');

if ($oldNotifs->isNotEmpty()) {
    DB::table('sys_notifications')->whereIn('contents', $oldNotifs)->delete();
    DB::table('sys_notification_div')->whereIn('id', $oldNotifs)->delete();
    DB::table('sys_notif_content')->whereIn('id', $oldNotifs)->delete();
    echo "  Cleaned up " . count($oldNotifs) . " old defense test notifications.\n";
}

$normalNotifDefs = [
    [
        'system' => 1, // DTS
        'content' => '[DEFENSE-TEST] New Transaction INT-2026-11-K9Z81A (Faculty Promotion Matrix) is waiting to be received by your office.',
        'redirect_url' => '/dts',
        'created_at' => Carbon::now()->subMinutes(12),
    ],
    [
        'system' => 1, // DTS
        'content' => '[DEFENSE-TEST] Transaction EXT-2026-11-L2P09Q (COA Audit Compliance Report) has been received by John Albert.',
        'redirect_url' => '/dts',
        'created_at' => Carbon::now()->subMinutes(25),
    ],
    [
        'system' => 6, // DCS
        'content' => '[DEFENSE-TEST] Document Request Form DRF-2026-0104 (Information Security Policy Manual) has been approved by the Document Control Center.',
        'redirect_url' => '/dcs',
        'created_at' => Carbon::now()->subHours(1),
    ],
    [
        'system' => 6, // DCS
        'content' => '[DEFENSE-TEST] Controlled Document DCN-2026-0198 (Revised Grading System Guidelines) has been officially distributed to your office.',
        'redirect_url' => '/dcs',
        'created_at' => Carbon::now()->subHours(2),
    ],
    [
        'system' => 2, // RDP
        'content' => '[DEFENSE-TEST] Records Disposal Schedule RDS-2026-0055 (Accounting Records Series 2017-2019) has completed appraisal and is ready for final authorization.',
        'redirect_url' => '/rdp',
        'created_at' => Carbon::now()->subHours(3),
    ],
];

foreach ($normalNotifDefs as $index => $def) {
    $contentId = DB::table('sys_notif_content')->insertGetId([
        'system' => $def['system'],
        'content' => $def['content'],
        'redirect_url' => $def['redirect_url'],
        'created_at' => $def['created_at'],
    ]);

    $notifId = DB::table('sys_notifications')->insertGetId([
        'office' => $officeCode,
        'contents' => $contentId,
        'created_at' => $def['created_at'],
    ]);

    // Ensure it is unread for user 1
    DB::table('sys_notification_div')->updateOrInsert(
        ['id' => $notifId, 'account_rec' => $userId],
        [
            'status' => 'unread',
            'is_in_user_list' => 1,
            'is_dismissed' => false,
            'processed_on' => Carbon::now(),
        ]
    );

    echo "  [+] Normal Notif " . ($index + 1) . ": ID={$notifId} [System {$def['system']}]\n";
}

echo "  => 5 Normal Notifications created successfully.\n\n";

// -------------------------------------------------------------
// 2. GENERATE 5 URGENT / PENDING ACTION TRANSACTIONS
// -------------------------------------------------------------
echo "2. Creating 5 Urgent / Pending Transactions...\n";

// Remove old demo transactions if any
$demoTransIds = [
    'TRANS-URGENT-DEMO01',
    'TRANS-URGENT-DEMO02',
    'TRANS-URGENT-DEMO03',
    'TRANS-URGENT-DEMO04',
    'TRANS-URGENT-DEMO05',
];

DB::table('dts_transaction_logs')->whereIn('transaction_id', $demoTransIds)->delete();
DB::table('dts_transaction_details')->whereIn('id', $demoTransIds)->delete();
DB::table('dts_transactions')->whereIn('transaction_id', $demoTransIds)->delete();

$urgentDefs = [
    // 1. Red (Critical Overdue - In Custody)
    [
        'id' => 'TRANS-URGENT-DEMO01',
        'control_number' => 'INT-2026-URG-001',
        'subject' => 'URGENT: Immediate Campus Infrastructure Safety Inspection',
        'originated_from' => 'President',
        'stage' => 'in_custody',
        'date_created' => Carbon::now()->subDays(2),
        'started_at' => Carbon::now()->subDays(2),
        'qr_code' => 'URGT-TEST-DEMO-0001',
        'notes' => 'Received by Dev. Pending immediate inspection report.',
    ],
    // 2. Red (Overdue - Incoming / Awaiting Receiving)
    [
        'id' => 'TRANS-URGENT-DEMO02',
        'control_number' => 'EXT-2026-URG-002',
        'subject' => 'CHED Memorandum Compliance Matrix & Faculty Clearance',
        'originated_from' => 'VPAA',
        'stage' => 'incoming',
        'date_created' => Carbon::now()->subHours(5),
        'started_at' => Carbon::now()->subHours(5),
        'qr_code' => 'URGT-TEST-DEMO-0002',
        'notes' => 'Forwarded from VPAA to Dev. Awaiting office intake receipt.',
    ],
    // 3. Yellow (Warning / Approaching limit - In Custody)
    [
        'id' => 'TRANS-URGENT-DEMO03',
        'control_number' => 'INT-2026-URG-003',
        'subject' => 'Procurement Endorsement for Computer Laboratory Equipment',
        'originated_from' => 'Procurement',
        'stage' => 'in_custody',
        'date_created' => Carbon::now()->subMinutes(8),
        'started_at' => Carbon::now()->subMinutes(8),
        'qr_code' => 'URGT-TEST-DEMO-0003',
        'notes' => 'Received by Dev. Approaching administrative time threshold.',
    ],
    // 4. Yellow (Warning / Approaching limit - Incoming)
    [
        'id' => 'TRANS-URGENT-DEMO04',
        'control_number' => 'INT-2026-URG-004',
        'subject' => 'Special Budget Reallocation for Final Defense Colloquium',
        'originated_from' => 'Budget',
        'stage' => 'incoming',
        'date_created' => Carbon::now()->subMinutes(7),
        'started_at' => Carbon::now()->subMinutes(7),
        'qr_code' => 'URGT-TEST-DEMO-0004',
        'notes' => 'Dispatched from Budget. Approaching receiving time threshold.',
    ],
    // 5. Green (Safe / Newly Received - In Custody)
    [
        'id' => 'TRANS-URGENT-DEMO05',
        'control_number' => 'INT-2026-URG-005',
        'subject' => 'Student Academic Verification & Graduation Clearance List',
        'originated_from' => 'SRR',
        'stage' => 'in_custody',
        'date_created' => Carbon::now()->subMinutes(2),
        'started_at' => Carbon::now()->subMinutes(2),
        'qr_code' => 'URGT-TEST-DEMO-0005',
        'notes' => 'Newly received by Dev. Within safe operational window.',
    ],
];

foreach ($urgentDefs as $index => $u) {
    // 0. dts_qr_code
    DB::table('dts_qr_code')->updateOrInsert(
        ['code_id' => $u['qr_code']],
        [
            'qr_status' => 'used',
            'created_at' => Carbon::now(),
        ]
    );

    // 1. dts_transactions
    DB::table('dts_transactions')->insert([
        'transaction_id' => $u['id'],
        'enable_notif' => 1,
        'trans_type' => 'internal',
        'qr_code' => $u['qr_code'],
        'current_office' => $officeCode,
        'status' => 'ongoing',
        'sequence' => 1,
    ]);

    // 2. dts_transaction_details
    DB::table('dts_transaction_details')->insert([
        'id' => $u['id'],
        'type' => 'internal',
        'created_by' => $userId,
        'originated_from' => $u['originated_from'],
        'current_office_hold' => $officeCode,
        'status' => 'ongoing',
        'document_password' => 'urgentdemo123',
        'email_access' => 1,
        'transaction_flow' => 'FLOW-CUSTOM-1791429611-280',
        'is_active' => 1,
        'date_created' => $u['date_created'],
        'control_number' => $u['control_number'],
        'subject' => $u['subject'],
        'action_needed' => 'For Urgent Action',
        'requestor_id' => $userId,
    ]);

    // 3. dts_transaction_logs
    if ($u['stage'] === 'in_custody') {
        // Forwarded from originator
        DB::table('dts_transaction_logs')->insert([
            'transaction_id' => $u['id'],
            'office_code' => $u['originated_from'],
            'type' => 'forwarded',
            'date_in' => $u['started_at'],
            'date_out' => $u['started_at'],
            'notes' => 'Forwarded to Dev',
            'performed_by' => $userId,
        ]);

        // Received at Dev (date_in is set, date_out is null)
        DB::table('dts_transaction_logs')->insert([
            'transaction_id' => $u['id'],
            'office_code' => $officeCode,
            'type' => 'received',
            'date_in' => $u['started_at'],
            'date_out' => null,
            'notes' => $u['notes'],
            'performed_by' => $userId,
        ]);
    } else {
        // Incoming (forwarded from originator, awaiting receiving at Dev - date_in is null)
        DB::table('dts_transaction_logs')->insert([
            'transaction_id' => $u['id'],
            'office_code' => $u['originated_from'],
            'type' => 'forwarded',
            'date_in' => $u['started_at'],
            'date_out' => $u['started_at'],
            'notes' => 'Forwarded to Dev',
            'performed_by' => $userId,
        ]);

        DB::table('dts_transaction_logs')->insert([
            'transaction_id' => $u['id'],
            'office_code' => $officeCode,
            'type' => 'forwarded',
            'date_in' => null,
            'date_out' => null,
            'notes' => $u['notes'],
            'performed_by' => $userId,
        ]);
    }

    echo "  [+] Urgent Trans " . ($index + 1) . ": {$u['control_number']} [{$u['stage']}]\n";
}

echo "  => 5 Urgent Transactions created successfully.\n\n";

echo "=== SUMMARY VERIFICATION ===\n";
echo "Done! Both 5 normal notifications and 5 urgent pending items are now ready in the database.\n";
