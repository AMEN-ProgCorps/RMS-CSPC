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
    private string $officeTable;
    private string $accDetailsTable;
    private string $condDetailsTable;
    private string $logsTable;
    private User $testUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->accDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->condDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
        $this->logsTable = \Illuminate\Support\Facades\Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $accTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';

        // 1. Create a test office
        $this->officeId = DB::table($this->officeTable)->insertGetId([
            'office_name' => 'Scanner Test Office',
            'office_code' => $this->myOfficeCode,
        ]);

        // 2. Resolve or create test user
        $user = User::first();
        if (!$user) {
            $uId = DB::table($accTable)->insertGetId([
                'username' => 'test_scanner_admin',
                'password' => bcrypt('secret123'),
                'account_role' => 999,
                'account_status' => 1,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);
            $user = User::find($uId);
        }

        $this->testUser = $user;

        // 3. Setup account_details and office mapping
        DB::table($this->accDetailsTable)
            ->updateOrInsert(
                ['account_id' => $user->id],
                [
                    'first_name' => 'Admin',
                    'last_name' => 'User',
                    'email' => 'admin@cspc.edu.ph',
                    'office_id' => $this->officeId
                ]
            );

        // 4. Ensure test user has DTS receive permissions
        if ($user->account_role) {
            DB::table($this->condDetailsTable)
                ->where('key_id', $user->account_role)
                ->update([
                    'is_sadm' => true,
                    'can_access_dts' => true,
                    'can_dts_user_received' => true,
                ]);
        }

        // Authenticate
        Auth::login($this->testUser);

        if (\Illuminate\Support\Facades\Schema::hasTable('dts_transaction_log_types')) {
            $types = [
                ['type_id' => 'created', 'type_label' => 'Created', 'description' => 'Created'],
                ['type_id' => 'forwarded', 'type_label' => 'Forwarded', 'description' => 'Forwarded'],
                ['type_id' => 'received', 'type_label' => 'Received', 'description' => 'Received'],
                ['type_id' => 'returned', 'type_label' => 'Returned for Revision', 'description' => 'Returned'],
                ['type_id' => 'completed', 'type_label' => 'Completed', 'description' => 'Completed'],
            ];
            foreach ($types as $t) {
                if (!DB::table('dts_transaction_log_types')->where('type_id', $t['type_id'])->exists()) {
                    DB::table('dts_transaction_log_types')->insert($t);
                }
            }
        }

        $subsystemsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $subsystems = [
            ['subsystem_id' => 1, 'subsystem_name' => 'Document Tracking System', 'subsystem_version' => '1.0.0', 'is_active' => true],
            ['subsystem_id' => 2, 'subsystem_name' => 'Records Disposition Program', 'subsystem_version' => '1.0.0', 'is_active' => true],
            ['subsystem_id' => 3, 'subsystem_name' => 'Admin Console', 'subsystem_version' => '1.0.0', 'is_active' => true],
            ['subsystem_id' => 4, 'subsystem_name' => 'Profile Manager', 'subsystem_version' => '1.0.0', 'is_active' => true],
            ['subsystem_id' => 5, 'subsystem_name' => 'Messify', 'subsystem_version' => '1.0.0', 'is_active' => true],
            ['subsystem_id' => 6, 'subsystem_name' => 'Document Control System', 'subsystem_version' => '1.0.0', 'is_active' => true],
        ];
        foreach ($subsystems as $sub) {
            DB::table($subsystemsTable)->updateOrInsert(
                ['subsystem_id' => $sub['subsystem_id']],
                $sub
            );
        }
    }

    private function createRequestor(string $name = 'Tester User', ?string $office = null): int
    {
        return DB::table('dts_requestor_history')->insertGetId([
            'requestor_name' => $name,
            'requestor_position' => 'Staff',
            'office' => $office ?? $this->myOfficeCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $user = $this->testUser;
        Auth::login($user);

        // Setup test predefined flow
        $flowId = 991;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow',
            'flow_code' => 'FLOW-SCAN-TEST',
            'added_by' => $user->id,
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
        $reqId = $this->createRequestor('Tester User');
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-1',
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-1',
            'subject' => 'Scanner Test Document',
            'requestor_id' => $reqId,
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
        $user = $this->testUser;
        Auth::login($user);

        // Setup other office first
        DB::table($this->officeTable)->updateOrInsert(
            ['office_code' => 'OTHER-OFF'],
            ['office_name' => 'Other Office']
        );

        // Setup test predefined flow
        $flowId = 992;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow 2',
            'flow_code' => 'FLOW-SCAN-TEST-2',
            'added_by' => $user->id,
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
        $reqId = $this->createRequestor('Tester User 2');
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-2',
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => 'OTHER-OFF',
            'current_office_hold' => 'OTHER-OFF',
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-2',
            'subject' => 'Scanner Test Document 2',
            'requestor_id' => $reqId,
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
        $user = $this->testUser;
        Auth::login($user);

        // Setup test predefined flow
        $flowId = 993;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow 3',
            'flow_code' => 'FLOW-SCAN-TEST-3',
            'added_by' => $user->id,
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
        $reqId = $this->createRequestor('Tester User 3');
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-3',
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-3',
            'subject' => 'Scanner Test Document 3',
            'requestor_id' => $reqId,
            'transaction_flow' => 'FLOW-SCAN-TEST-3',
            'date_created' => now(),
        ]);

        // Create log entry representing receipt
        DB::table($this->logsTable)->insert([
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
        $user = $this->testUser;
        Auth::login($user);

        $flowId = 994;
        DB::table('dts_transaction_flow')->updateOrInsert(
            ['flow_code' => 'FLOW-SCAN-TEST'],
            [
                'id' => $flowId,
                'flow_name' => 'Scan Test Flow',
                'added_by' => $user->id,
                'date_added' => now(),
                'flow_use' => 'internal',
                'is_active' => true,
            ]
        );

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

        // Insert mock transaction details second
        $reqId = $this->createRequestor('Tester User');
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-COMP',
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'completed',
            'control_number' => 'CTRL-SCAN-COMP',
            'subject' => 'Completed Test Document',
            'requestor_id' => $reqId,
            'transaction_flow' => 'FLOW-SCAN-TEST',
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
        $user = $this->testUser;
        Auth::login($user);

        Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-NOT-EXIST-999')
            ->call('loadTransaction')
            ->assertSet('errorMessage', 'Invalid QR Code: Only valid, registered QR codes can be processed by the scanner.')
            ->assertSet('activeTransaction', null);
    }

    /**
     * A document may only be received ONCE per office visit.
     */
    public function test_scanner_blocks_duplicate_receive()
    {
        $user = $this->testUser;
        Auth::login($user);

        $flowId = 995;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Scan Test Flow 5',
            'flow_code' => 'FLOW-SCAN-TEST-5',
            'added_by' => $user->id,
            'date_added' => now(),
            'flow_use' => 'internal',
            'is_active' => true,
        ]);

        DB::table('dts_sequence_list')->insert([
            [
                'control_id' => $flowId,
                'sequence_ranking' => 1,
                'office_code' => $this->myOfficeCode,
                'date_in' => now()->subMinutes(5),
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

        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-SCAN-5',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => 'TRANS-SCAN-5',
            'trans_type' => 'internal',
            'qr_code' => 'QR-SCAN-5',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
        ]);

        $reqId = $this->createRequestor('Tester User 5');
        DB::table('dts_transaction_details')->insert([
            'id' => 'TRANS-SCAN-5',
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-SCAN-5',
            'subject' => 'Duplicate Receive Test Document',
            'requestor_id' => $reqId,
            'transaction_flow' => 'FLOW-SCAN-TEST-5',
            'date_created' => now(),
        ]);

        // Pending receipt at this office (forwarded to us, date_in not yet stamped)
        DB::table($this->logsTable)->insert([
            'transaction_id' => 'TRANS-SCAN-5',
            'office_code' => $this->myOfficeCode,
            'type' => 'forwarded',
            'date_in' => null,
            'date_out' => null,
            'notes' => '',
            'performed_by' => null,
        ]);

        $component = Volt::test('pages.dts.scanner')
            ->set('scannedCode', 'QR-SCAN-5')
            ->call('loadTransaction')
            ->assertSet('errorMessage', '')
            ->assertSet('activeTransaction.is_received_here', false);

        // First receive succeeds, then the console clears itself so the same
        // scan cannot be used for a second action (e.g. accidental Forward).
        $component->call('executeReceive')
            ->assertSet('errorMessage', '')
            ->assertSet('activeTransaction', null)
            ->assertSet('scannedCode', '');

        $this->assertStringContainsString('RECEIVED', $component->get('successMessage'));

        // Re-scanning the same code reports it as already received here...
        $component->call('loadTransaction')
            ->assertSet('errorMessage', '')
            ->assertSet('activeTransaction.is_received_here', true);

        // ...and a second Receive is rejected by the server-side guard
        $component->call('executeReceive')
            ->assertSet('errorMessage', "Document 'CTRL-SCAN-5' has already been received at your office.");

        // Exactly one received log row for this office - no duplicates
        $this->assertEquals(1, DB::table($this->logsTable)
            ->where('transaction_id', 'TRANS-SCAN-5')
            ->where('office_code', $this->myOfficeCode)
            ->where('type', 'received')
            ->count());

        $this->assertEquals(1, DB::table($this->logsTable)
            ->where('transaction_id', 'TRANS-SCAN-5')
            ->where('office_code', $this->myOfficeCode)
            ->count());
    }

    /**
     * Test that the portal access page displays the DTS Scanner shortcut link only for users with receive permission.
     */
    public function test_portal_page_shows_scanner_shortcut_based_on_permissions()
    {
        $user = $this->testUser;
        Auth::login($user);

        // Ensure subsystems are active
        $subsystemsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        DB::table($subsystemsTable)->whereIn('subsystem_name', ['Document Tracking System', 'Messify'])->update([
            'is_active' => true,
        ]);

        // Scenario 1: User has receive permission
        DB::table($this->condDetailsTable)
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
        DB::table($this->condDetailsTable)
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

    /**
     * Test DTS index page and scanner modal render cleanly without SQL or view errors.
     */
    public function test_dts_index_and_scanner_modal_render_without_error()
    {
        $user = $this->testUser;
        Auth::login($user);

        $response = $this->actingAs($user)->get('/dts');
        $response->assertStatus(200);

        Volt::test('components.scanner-modal')
            ->assertSet('isOpen', false)
            ->call('openModal')
            ->assertSet('isOpen', true)
            ->assertSee('dts-scanner-widget-card')
            ->call('openAvailableCodesModal')
            ->assertSet('showAvailableModal', true)
            ->assertSee('dts-available-inner-pane')
            ->assertSee('Available Station Documents')
            ->call('closeAvailableCodesModal')
            ->assertSet('showAvailableModal', false)
            ->call('closeModal')
            ->assertSet('isOpen', false);
    }

    /**
     * Test server settings toggle for disabling Available Codes enforces priority scanning mode.
     */
    public function test_scanner_available_codes_toggle_enforces_priority_scanning_when_disabled()
    {
        $user = $this->testUser;
        Auth::login($user);

        $sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';

        // 1. Verify toggle in Current Server settings page
        DB::table($sysTable)->updateOrInsert(['key' => 'dts_enable_available_codes'], ['value' => 'true']);
        Volt::test('pages.admin.server-settings.current-server')
            ->assertSet('dtsEnableAvailableCodes', true)
            ->call('toggleAvailableCodes')
            ->assertSet('dtsEnableAvailableCodes', false);

        $this->assertEquals('false', DB::table($sysTable)->where('key', 'dts_enable_available_codes')->value('value'));

        // 2. When disabled, scanner modal hides Available Codes directory and enforces priority scanning
        Volt::test('components.scanner-modal')
            ->call('openModal')
            ->assertSet('isOpen', true)
            ->assertSet('availableCodesEnabled', false)
            ->assertDontSee('Browse Available Documents')
            ->assertSee('Camera QR Scanner')
            ->assertSee('Barcode Gun / Manual')
            ->call('openAvailableCodesModal')
            ->assertSet('showAvailableModal', false); // Remains blocked/closed

        // 3. Re-enable via server settings and verify directory returns
        Volt::test('pages.admin.server-settings.current-server')
            ->call('toggleAvailableCodes')
            ->assertSet('dtsEnableAvailableCodes', true);

        $this->assertEquals('true', DB::table($sysTable)->where('key', 'dts_enable_available_codes')->value('value'));

        Volt::test('components.scanner-modal')
            ->call('openModal')
            ->assertSet('availableCodesEnabled', true)
            ->assertSee('Browse Available Documents')
            ->call('openAvailableCodesModal')
            ->assertSet('showAvailableModal', true);
    }

    /**
     * Test scanning an incoming document auto-receives it immediately and initiates 5s cooldown.
     */
    public function test_scanner_scan_auto_receives_incoming_document_and_triggers_cooldown()
    {
        $user = $this->testUser;
        Auth::login($user);

        // Setup mock QR code and incoming transaction
        $flowId = 998;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Auto-Receive Flow',
            'flow_code' => 'FLOW-AUTO-RCV',
            'added_by' => $user->id,
            'date_added' => now(),
            'flow_use' => 'internal',
            'is_active' => true,
        ]);

        DB::table('dts_sequence_list')->insert([
            'control_id' => $flowId,
            'sequence_ranking' => 1,
            'office_code' => $this->myOfficeCode,
            'date_in' => null,
            'date_out' => null,
            'scanned_id' => false,
        ]);

        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-AUTO-RCV-1',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        $transId = 'TRANS-AUTO-RCV-1';
        DB::table('dts_transactions')->insert([
            'transaction_id' => $transId,
            'trans_type' => 'internal',
            'qr_code' => 'QR-AUTO-RCV-1',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $transId,
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-AUTO-RCV-1',
            'subject' => 'Test Auto Receive on Scan',
            'transaction_flow' => 'FLOW-AUTO-RCV',
            'date_created' => now(),
        ]);

        // When scanned (isScan = true): auto-receives immediately
        Volt::test('components.scanner-modal')
            ->call('openModal')
            ->call('loadTransaction', 'QR-AUTO-RCV-1', true)
            ->assertSet('errorMessage', '')
            ->assertDispatched('scanner-cooldown-start')
            ->assertSet('activeTransaction.is_received_here', true)
            ->assertSee('received successfully');

        // Verify in DB that it was marked received
        $log = DB::table($this->logsTable)
            ->where('transaction_id', $transId)
            ->where('office_code', $this->myOfficeCode)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals('received', $log->type);
    }

    /**
     * Test typing code manually requires verification via Receive button instead of auto-receiving.
     */
    public function test_scanner_manual_type_requires_verification_receive_button()
    {
        $user = $this->testUser;
        Auth::login($user);

        $flowId = 999;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Manual Verify Flow',
            'flow_code' => 'FLOW-MANUAL-VERIFY',
            'added_by' => $user->id,
            'date_added' => now(),
            'flow_use' => 'internal',
            'is_active' => true,
        ]);

        DB::table('dts_sequence_list')->insert([
            'control_id' => $flowId,
            'sequence_ranking' => 1,
            'office_code' => $this->myOfficeCode,
            'date_in' => null,
            'date_out' => null,
            'scanned_id' => false,
        ]);

        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-MANUAL-VERIFY-1',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        $transId = 'TRANS-MANUAL-VERIFY-1';
        DB::table('dts_transactions')->insert([
            'transaction_id' => $transId,
            'trans_type' => 'internal',
            'qr_code' => 'QR-MANUAL-VERIFY-1',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $transId,
            'type' => 'internal',
            'created_by' => $user->id,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'control_number' => 'CTRL-MANUAL-VERIFY-1',
            'subject' => 'Test Manual Verify',
            'transaction_flow' => 'FLOW-MANUAL-VERIFY',
            'date_created' => now(),
        ]);

        // When typed manually (loadTransactionManual / isScan = false):
        // Does NOT auto-receive, activeTransaction is incoming, shows "Receive Transaction"
        Volt::test('components.scanner-modal')
            ->call('openModal')
            ->set('scannedCode', 'QR-MANUAL-VERIFY-1')
            ->call('loadTransactionManual')
            ->assertSet('isManualInput', true)
            ->assertSet('activeTransaction.is_received_here', false)
            ->assertSee('Receive Transaction');

        // DB log should NOT exist yet
        $logCount = DB::table($this->logsTable)
            ->where('transaction_id', $transId)
            ->where('office_code', $this->myOfficeCode)
            ->count();
        $this->assertEquals(0, $logCount);
    }

    /**
     * Test cooldown blocks rapid scanning within 5 seconds.
     */
    public function test_scanner_cooldown_blocks_rapid_scans()
    {
        $user = $this->testUser;
        Auth::login($user);

        Volt::test('components.scanner-modal')
            ->call('openModal')
            ->set('cooldownUntil', time() + 5)
            ->call('loadTransaction', 'SOME-CODE', true)
            ->assertSee('Scanner cooldown active')
            ->assertDispatched('scanner-code-invalid');
    }

    /**
     * Test single widget open rule: scanner closes when another widget opens.
     */
    public function test_scanner_closes_when_another_widget_opens()
    {
        $user = $this->testUser;
        Auth::login($user);

        Volt::test('components.scanner-modal')
            ->call('openModal')
            ->assertSet('isOpen', true)
            ->dispatch('rms-widget-opened', widget: 'messify')
            ->assertSet('isOpen', false);
    }
}
