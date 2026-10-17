<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class IssuancesHubSelfExclusionTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $roleId;
    private int $officeId;
    private string $officeCode = 'TEST_ORIGIN_DEV';

    private string $tOffice;
    private string $tAccount;
    private string $tAccountDetails;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccount = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        $roleTable = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $roleDetails = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';

        $base = (int) (DB::table($roleTable)->max('id') ?: 200) + 1;
        $this->roleId = $base;

        DB::table($roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => true,
            'can_access_dts' => true,
            'can_access_rdp' => true,
            'can_access_dcs' => true,
            'can_dts_use_issuance' => true,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Hub Test Role ' . $base,
            'key_description' => 'Test role',
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $this->officeId = DB::table($this->tOffice)->insertGetId([
            'office_name' => 'Test Origin Dev Team ' . $base,
            'office_code' => $this->officeCode,
            'is_active' => true,
        ]);

        // Insert target recipient offices
        DB::table($this->tOffice)->updateOrInsert(
            ['office_code' => 'TARGET_A'],
            ['office_name' => 'Target Unit A', 'is_active' => true]
        );
        DB::table($this->tOffice)->updateOrInsert(
            ['office_code' => 'TARGET_B'],
            ['office_name' => 'Target Unit B', 'is_active' => true]
        );

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'hub_user_' . $base,
            'password' => bcrypt('password'),
            'account_status' => 1,
            'account_role' => $this->roleId,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Hub',
            'last_name' => 'Tester',
            'office_id' => $this->officeId,
            'email' => 'hub_' . $base . '@example.com',
        ]);

        $user = User::find($this->userId);
        Auth::login($user);
    }

    public function test_select_free_flow_office_rejects_origin_office_and_placeholders()
    {
        $component = Livewire::test('pages.dts.create.issuances');
        $component->set('userOfficeCode', $this->officeCode);

        // Attempting to select origin office should be ignored
        $component->call('selectFreeFlowOffice', $this->officeCode);
        $this->assertNotContains($this->officeCode, $component->get('free_flow_receiving_offices'));

        // Attempting to select placeholders should be ignored
        $component->call('selectFreeFlowOffice', '[HUB]');
        $this->assertNotContains('[HUB]', $component->get('free_flow_receiving_offices'));

        $component->call('selectFreeFlowOffice', 'ORIGIN');
        $this->assertNotContains('ORIGIN', $component->get('free_flow_receiving_offices'));

        // Selecting a valid external office works
        $component->call('selectFreeFlowOffice', 'TARGET_A');
        $this->assertContains('TARGET_A', $component->get('free_flow_receiving_offices'));
    }

    public function test_select_custom_flow_hub_office_rejects_origin_office()
    {
        $component = Livewire::test('pages.dts.create.issuances');
        $component->set('userOfficeCode', $this->officeCode);

        $component->call('selectCustomFlowHubOffice', $this->officeCode);
        $this->assertNotContains($this->officeCode, $component->get('customFlowHubOffices'));

        $component->call('selectCustomFlowHubOffice', 'TARGET_B');
        $this->assertContains('TARGET_B', $component->get('customFlowHubOffices'));
    }

    public function test_transaction_visible_path_matches_sequence_list_and_does_not_duplicate_from_logs()
    {
        // 1. Create a 3-step flow: ORIGIN -> TARGET_A -> ORIGIN
        $maxFlowId = (DB::table('dts_transaction_flow')->max('id') ?? 0) + 1;
        $flowCode = 'FLOW-TEST-NODUP-' . $maxFlowId;
        DB::table('dts_transaction_flow')->insert([
            'id' => $maxFlowId,
            'flow_name' => 'Test Flow No Duplicate',
            'flow_code' => $flowCode,
            'is_active' => true,
            'flow_use' => 'internal',
            'flow_for' => 'system',
            'added_by' => $this->userId,
            'date_added' => now(),
        ]);

        DB::table('dts_sequence_list')->insert([
            ['control_id' => $maxFlowId, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN', 'date_in' => now(), 'date_out' => now(), 'action_needed' => 'Created'],
            ['control_id' => $maxFlowId, 'sequence_ranking' => 2, 'office_code' => 'TARGET_A', 'date_in' => now(), 'date_out' => now(), 'action_needed' => 'Reviewed'],
            ['control_id' => $maxFlowId, 'sequence_ranking' => 3, 'office_code' => 'ORIGIN', 'date_in' => now(), 'date_out' => null, 'action_needed' => 'Approval'],
        ]);

        // 2. Create transaction
        $transId = 'TRANS-TEST-NODUP';
        DB::table('dts_qr_code')->insert([
            'code_id' => 'QR-TEST-NODUP',
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => $transId,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $this->officeCode,
            'qr_code' => 'QR-TEST-NODUP',
            'status' => 'ongoing',
            'sequence' => 3,
        ]);
        DB::table('dts_transaction_details')->insert([
            'id' => $transId,
            'type' => 'internal',
            'created_by' => $this->userId,
            'originated_from' => $this->officeCode,
            'transaction_flow' => $flowCode,
            'control_number' => 'INT-TEST-001',
            'subject' => 'Test no dup',
            'action_needed' => 'Approval',
            'current_office_hold' => $this->officeCode,
            'status' => 'ongoing',
            'date_created' => now(),
        ]);

        // 3. Insert 5 logs (forwarded & received pairs)
        DB::table('dts_transaction_logs')->insert([
            ['transaction_id' => $transId, 'office_code' => $this->officeCode, 'type' => 'received', 'date_in' => now(), 'date_out' => now(), 'notes' => 'Created', 'performed_by' => $this->userId],
            ['transaction_id' => $transId, 'office_code' => 'TARGET_A', 'type' => 'forwarded', 'date_in' => null, 'date_out' => null, 'notes' => 'Forwarded', 'performed_by' => $this->userId],
            ['transaction_id' => $transId, 'office_code' => 'TARGET_A', 'type' => 'received', 'date_in' => now(), 'date_out' => now(), 'notes' => 'Received', 'performed_by' => $this->userId],
            ['transaction_id' => $transId, 'office_code' => $this->officeCode, 'type' => 'forwarded', 'date_in' => null, 'date_out' => null, 'notes' => 'Forwarded back', 'performed_by' => $this->userId],
            ['transaction_id' => $transId, 'office_code' => $this->officeCode, 'type' => 'received', 'date_in' => now(), 'date_out' => null, 'notes' => 'Held', 'performed_by' => $this->userId],
        ]);

        // 4. Open transaction in DTS index component
        $component = Livewire::test('pages.dts.index')
            ->call('openTransaction', $transId);

        $visiblePath = $component->get('visiblePath');

        // Path MUST have 3 steps (ORIGIN, TARGET_A, ORIGIN), NOT 5 steps from logs!
        $this->assertCount(3, $visiblePath);
        $this->assertEquals($this->officeCode, $visiblePath[0]->office_code);
        $this->assertEquals('TARGET_A', $visiblePath[1]->office_code);
        $this->assertEquals($this->officeCode, $visiblePath[2]->office_code);
    }
}
