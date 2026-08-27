<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class DtsScannerTest extends TestCase
{
    use DatabaseTransactions;

    private int $officeId;
    private string $myOfficeCode = 'TEST-OFF-SCAN';

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create a test office
        $this->officeId = DB::table('office')->insertGetId([
            'office_name' => 'Scanner Test Office',
            'office_code' => $this->myOfficeCode,
        ]);

        // 2. Setup user 1 account_details and office mapping
        DB::table('account_details')
            ->updateOrInsert(
                ['account_id' => 1],
                [
                    'first_name' => 'Admin',
                    'last_name' => 'User',
                    'email' => 'admin@cspc.edu.ph',
                    'office_id' => $this->officeId
                ]
            );

        // 3. Ensure user 1 has DTS receive permissions
        $user = User::find(1);
        if ($user && $user->account_role) {
            DB::table('condition_details')
                ->where('key_id', $user->account_role)
                ->update([
                    'is_sadm' => true,
                    'can_access_dts' => true,
                    'can_dts_user_received' => true,
                ]);
        }

        // Authenticate
        if ($user) {
            Auth::login($user);
        }
    }

    /**
     * Test page authorization constraints.
     */
    public function test_scanner_page_access_restricted()
    {
        Auth::logout();
        $response = $this->get(route('dts.scanner'));
        $response->assertRedirect(route('login'));
    }

    /**
     * Test loading a valid transaction routed to the user's office.
     */
    public function test_scanner_loads_transaction_at_user_office()
    {
        $user = User::find(1);
        Auth::login($user);

        // Setup test predefined flow
        $flowId = 991;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow',
            'flow_code' => 'FLOW-SCAN-TEST',
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'internal',
            'is_active' => true,
        ]);

        DB::table('dts_sequence_list')->insert([
            [
                'control_id' => $flowId,
                'sequence_ranking' => 1,
                'office_code' => $this->myOfficeCode,
                'date_in' => now(),
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
            [
                'control_id' => $flowId,
                'sequence_ranking' => 2,
                'office_code' => 'ORIGIN',
                'date_in' => null,
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
        ]);

        // Insert mock QR code first
        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-SCAN-1',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        // Insert into dts_transactions first (satisfies foreign key constraints)
        DB::table('dts_transactions')->insert([
            'transaction_id' => 'TRANS-SCAN-1',
            'trans_type' => 'internal',
            'qr_code' => 'QR-SCAN-1',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
        ]);

        // Insert mock transaction details second
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-1',
            'type' => 'internal',
            'created_by' => 1,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-1',
            'subject' => 'Scanner Test Document',
            'requestor_name' => 'Tester User',
            'transaction_flow' => 'FLOW-SCAN-TEST',
            'date_created' => now(),
        ]);

        // Track path and ensure dts_scans.log gets written
        $logFile = storage_path('logs/dts_scans.log');
        if (File::exists($logFile)) {
            File::delete($logFile);
        }

        Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-SCAN-1')
            ->call('loadTransaction')
            ->assertSet('errorMessage', '')
            ->assertSet('activeTransaction.control_number', 'CTRL-SCAN-1');

        $this->assertTrue(File::exists($logFile));
        $content = File::get($logFile);
        $this->assertStringContainsString('QR-SCAN-1', $content);
    }

    /**
     * Test that scanning a transaction at another office triggers warning.
     */
    public function test_scanner_warns_on_transaction_at_other_office()
    {
        $user = User::find(1);
        Auth::login($user);

        // Setup other office first
        DB::table('office')->insert([
            'office_name' => 'Other Office',
            'office_code' => 'OTHER-OFF',
        ]);

        // Setup test predefined flow
        $flowId = 992;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow 2',
            'flow_code' => 'FLOW-SCAN-TEST-2',
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'internal',
            'is_active' => true,
        ]);

        DB::table('dts_sequence_list')->insert([
            [
                'control_id' => $flowId,
                'sequence_ranking' => 1,
                'office_code' => 'OTHER-OFF',
                'date_in' => now(),
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
        ]);

        // Insert mock QR code first
        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-SCAN-2',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        // Insert into dts_transactions first (satisfies foreign key constraints)
        DB::table('dts_transactions')->insert([
            'transaction_id' => 'TRANS-SCAN-2',
            'trans_type' => 'internal',
            'qr_code' => 'QR-SCAN-2',
            'current_office' => 'OTHER-OFF',
            'status' => 'ongoing',
            'sequence' => 1,
        ]);

        // Insert mock transaction details second
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-2',
            'type' => 'internal',
            'created_by' => 1,
            'originated_from' => 'OTHER-OFF',
            'current_office_hold' => 'OTHER-OFF',
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-2',
            'subject' => 'Scanner Test Document 2',
            'requestor_name' => 'Tester User 2',
            'transaction_flow' => 'FLOW-SCAN-TEST-2',
            'date_created' => now(),
        ]);

        Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-SCAN-2')
            ->call('loadTransaction')
            ->assertSet('errorMessage', 'That QR code is no longer within your office transaction list.');
    }

    /**
     * Test successful receive and proceed logic.
     */
    public function test_proceed_transaction_moves_forward_and_flags_scanned_id()
    {
        $user = User::find(1);
        Auth::login($user);

        // Setup test predefined flow
        $flowId = 993;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow 3',
            'flow_code' => 'FLOW-SCAN-TEST-3',
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'internal',
            'is_active' => true,
        ]);

        DB::table('dts_sequence_list')->insert([
            [
                'control_id' => $flowId,
                'sequence_ranking' => 1,
                'office_code' => $this->myOfficeCode,
                'date_in' => now()->subMinutes(10),
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
            [
                'control_id' => $flowId,
                'sequence_ranking' => 2,
                'office_code' => 'ORIGIN',
                'date_in' => null,
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
        ]);

        // Insert mock QR code first
        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-SCAN-3',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        // Insert into dts_transactions first (satisfies foreign key constraints)
        DB::table('dts_transactions')->insert([
            'transaction_id' => 'TRANS-SCAN-3',
            'trans_type' => 'internal',
            'qr_code' => 'QR-SCAN-3',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
        ]);

        // Insert mock transaction details second
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-3',
            'type' => 'internal',
            'created_by' => 1,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-3',
            'subject' => 'Scanner Test Document 3',
            'requestor_name' => 'Tester User 3',
            'transaction_flow' => 'FLOW-SCAN-TEST-3',
            'date_created' => now(),
        ]);

        // Create log entry representing receipt
        DB::table('sub_document_tracking_system_logs')->insert([
            'transaction_id' => 'TRANS-SCAN-3',
            'office_code' => $this->myOfficeCode,
            'type' => 'received',
            'date_in' => now()->subMinutes(10),
            'date_out' => null,
            'notes' => '',
            'performed_by' => null,
        ]);

        Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-SCAN-3')
            ->call('loadTransaction')
            ->set('actionNeeded', 'For Signature')
            ->set('notes', 'Scanned note text')
            ->call('executeForward')
            ->assertSet('errorMessage', '')
            ->assertSet('activeTransaction', null);

        // Verify next step rank updated
        $tx = DB::table('dts_transactions')->where('transaction_id', 'TRANS-SCAN-3')->first();
        $this->assertEquals(2, $tx->sequence);
        $this->assertEquals($this->myOfficeCode, $tx->current_office); // Next office is ORIGIN which resolves to myOfficeCode

        // Verify first sequence step flag updated
        $step1 = DB::table('dts_sequence_list')
            ->where('control_id', $flowId)
            ->where('sequence_ranking', 1)
            ->first();
        $this->assertNotNull($step1->date_out);
        $this->assertEquals('For Signature', $step1->action_needed);
        $this->assertEquals('Scanned note text', $step1->note);
        $this->assertEquals(1, $step1->scanned_id); // scanned_id set to true!
    }

    /**
     * Test that scanning a completed transaction returns finished message.
     */
    public function test_scanner_rejects_completed_transaction()
    {
        $user = User::find(1);
        Auth::login($user);

        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-SCAN-COMPLETED',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => 'TRANS-SCAN-COMP',
            'trans_type' => 'internal',
            'qr_code' => 'QR-SCAN-COMPLETED',
            'current_office' => $this->myOfficeCode,
            'status' => 'completed',
            'sequence' => 1,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-COMP',
            'type' => 'internal',
            'created_by' => 1,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'completed',
            'control_number' => 'CTRL-SCAN-COMP',
            'subject' => 'Completed Test Document',
            'requestor_name' => 'Tester User',
            'date_created' => now(),
        ]);

        Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-SCAN-COMPLETED')
            ->call('loadTransaction')
            ->assertSet('errorMessage', 'That QR code is already finished its transaction.')
            ->assertSet('activeTransaction', null);
    }

    /**
     * Test that scanning an unregistered QR code returns invalid QR code error.
     */
    public function test_scanner_rejects_unregistered_qr_code()
    {
        $user = User::find(1);
        Auth::login($user);

        Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-NOT-EXIST-999')
            ->call('loadTransaction')
            ->assertSet('errorMessage', 'Invalid QR Code: Only valid, registered QR codes can be processed by the scanner.')
            ->assertSet('activeTransaction', null);
    }

    /**
     * Test that the portal access page displays the DTS Scanner shortcut link only for users with receive permission.
     */
    public function test_portal_page_shows_scanner_shortcut_based_on_permissions()
    {
        $user = User::find(1);
        Auth::login($user);

        // Ensure subsystems are active
        DB::table('subsystems')->updateOrInsert(
            ['subsystem_name' => 'Document Tracking System'],
            ['is_active' => true]
        );
        DB::table('subsystems')->updateOrInsert(
            ['subsystem_name' => 'Chatify'],
            ['is_active' => true]
        );

        // Scenario 1: User has receive permission
        DB::table('condition_details')
            ->where('key_id', $user->account_role)
            ->update([
                'is_sadm' => false,
                'can_access_dts' => true,
                'can_dts_user_received' => true,
            ]);

        Auth::setUser($user->fresh());

        Volt::test('pages.portal.access-page')
            ->assertSee('DTS QR Scanner')
            ->assertSee('mobile-only')
            ->assertSee('desktop-only')
            ->assertSee('Chatify');

        // Scenario 2: User does not have receive permission
        DB::table('condition_details')
            ->where('key_id', $user->account_role)
            ->update([
                'is_sadm' => false,
                'can_access_dts' => true,
                'can_dts_user_received' => false,
            ]);

        Auth::setUser($user->fresh());

        Volt::test('pages.portal.access-page')
            ->assertDontSee('DTS QR Scanner')
            ->assertSee('desktop-only')
            ->assertSee('Chatify');
    }
}
