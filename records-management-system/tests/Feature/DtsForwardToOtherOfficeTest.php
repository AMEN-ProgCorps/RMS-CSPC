<?php

namespace Tests\Feature;

use App\Models\role_permission;
use App\Models\User;
use App\Services\DtsRoutingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DtsForwardToOtherOfficeTest extends TestCase
{
    use DatabaseTransactions;

    private string $tOffice;
    private string $tAccount;
    private string $tAccountDetails;
    private string $tConditionKey;
    private string $tConditionDetails;

    private int $roleNoClearanceId;
    private int $roleWithClearanceId;
    private User $userNoClearance;
    private User $userWithClearance;

    private string $officeA = 'TST_OFF_A';
    private string $officeB = 'TST_OFF_B';
    private string $officeC = 'TST_OFF_C';
    private string $officeTarget = 'TST_OFF_TGT';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccount = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->tConditionKey = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $this->tConditionDetails = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';

        $maxKeyId = (int) (DB::table($this->tConditionKey)->max('id') ?: 100);

        // Create offices
        $offices = [
            $this->officeA => 'Test Office Alpha',
            $this->officeB => 'Test Office Beta',
            $this->officeC => 'Test Office Gamma',
            $this->officeTarget => 'Test Office Target Destination',
        ];

        foreach ($offices as $code => $name) {
            DB::table($this->tOffice)->updateOrInsert(
                ['office_code' => $code],
                [
                    'office_name' => $name,
                    'is_active' => true,
                ]
            );
        }

        // Role 1: Normal user WITHOUT clearance
        $this->roleNoClearanceId = $maxKeyId + 1;
        DB::table($this->tConditionDetails)->insert([
            'key_id' => $this->roleNoClearanceId,
            'is_sadm' => false,
            'can_access_dts' => true,
            'can_dts_user_received' => true,
            'can_dts_forward_to_other_office' => false,
        ]);
        DB::table($this->tConditionKey)->insert([
            'id' => $this->roleNoClearanceId,
            'key_name' => 'Test Role Without Clearance',
            'key_description' => 'Cannot forward to other office',
            'modifier_key' => $this->roleNoClearanceId,
            'is_active' => true,
        ]);

        // Role 2: Normal user WITH regular clearance (is_sadm = false, is_admin = false)
        $this->roleWithClearanceId = $maxKeyId + 2;
        DB::table($this->tConditionDetails)->insert([
            'key_id' => $this->roleWithClearanceId,
            'is_sadm' => false,
            'can_access_dts' => true,
            'can_dts_user_received' => true,
            'can_dts_forward_to_other_office' => true,
        ]);
        DB::table($this->tConditionKey)->insert([
            'id' => $this->roleWithClearanceId,
            'key_name' => 'Test Role With Clearance',
            'key_description' => 'Can forward to other office',
            'modifier_key' => $this->roleWithClearanceId,
            'is_active' => true,
        ]);

        // Create User 1 (No clearance)
        $u1Id = DB::table($this->tAccount)->insertGetId([
            'username' => 'test_user_no_clearance_' . uniqid(),
            'password' => bcrypt('secret123'),
            'account_role' => $this->roleNoClearanceId,
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);
        DB::table($this->tAccountDetails)->insert([
            'account_id' => $u1Id,
            'first_name' => 'NoClearance',
            'last_name' => 'User',
            'email' => 'noclearance_' . uniqid() . '@example.test',
            'office_id' => DB::table($this->tOffice)->where('office_code', $this->officeA)->value('id') ?? 1,
        ]);
        $this->userNoClearance = User::find($u1Id);

        // Create User 2 (With clearance)
        $u2Id = DB::table($this->tAccount)->insertGetId([
            'username' => 'test_user_with_clearance_' . uniqid(),
            'password' => bcrypt('secret123'),
            'account_role' => $this->roleWithClearanceId,
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);
        DB::table($this->tAccountDetails)->insert([
            'account_id' => $u2Id,
            'first_name' => 'WithClearance',
            'last_name' => 'User',
            'email' => 'withclearance_' . uniqid() . '@example.test',
            'office_id' => DB::table($this->tOffice)->where('office_code', $this->officeA)->value('id') ?? 1,
        ]);
        $this->userWithClearance = User::find($u2Id);
    }

    private function createTestTransaction(string $flowCode = 'FLOW-SHARED-TEST'): array
    {
        $maxFlowId = (int) (DB::table('dts_transaction_flow')->max('id') ?: 100);
        $flowId = $maxFlowId + 1;

        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_code' => $flowCode,
            'flow_name' => 'Shared Flow ' . $flowCode,
            'flow_use' => 'none',
            'flow_for' => 'system',
            'is_active' => 1,
            'added_by' => $this->userWithClearance->id,
            'date_added' => now(),
        ]);

        // Sequence steps: Office A (seq 1), Office B (seq 2), Office C (seq 3)
        DB::table('dts_sequence_list')->insert([
            [
                'control_id' => $flowId,
                'sequence_ranking' => 1,
                'office_code' => $this->officeA,
                'date_in' => now()->subHour(),
                'date_out' => null,
                'action_needed' => 'For Review',
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
            [
                'control_id' => $flowId,
                'sequence_ranking' => 2,
                'office_code' => $this->officeB,
                'date_in' => null,
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
            [
                'control_id' => $flowId,
                'sequence_ranking' => 3,
                'office_code' => $this->officeC,
                'date_in' => null,
                'date_out' => null,
                'action_needed' => null,
                'note' => null,
                'total_time_completed' => null,
                'scanned_id' => false,
            ],
        ]);

        $txId = 'TX-' . strtoupper(uniqid());
        $ctrlNum = 'CTRL-' . strtoupper(uniqid());
        $qrCode = 'QR-' . strtoupper(uniqid());

        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCode,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        DB::table('dts_transactions')->insert([
            'transaction_id' => $txId,
            'current_office' => $this->officeA,
            'sequence' => 1,
            'status' => 'ongoing',
            'trans_type' => 'internal',
            'qr_code' => $qrCode,
            'enable_notif' => true,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $txId,
            'control_number' => $ctrlNum,
            'subject' => 'Test Transaction Subject',
            'originated_from' => $this->officeA,
            'transaction_flow' => $flowCode,
            'current_office_hold' => $this->officeA,
            'type' => 'internal',
            'status' => 'ongoing',
            'date_created' => now(),
            'is_active' => 1,
            'created_by' => $this->userWithClearance->id,
        ]);

        $logTable = Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        DB::table($logTable)->insert([
            'transaction_id' => $txId,
            'office_code' => $this->officeA,
            'type' => 'received',
            'date_in' => now()->subHour(),
            'date_out' => null,
            'notes' => 'Received at Alpha',
            'performed_by' => $this->userWithClearance->id,
        ]);

        return ['txId' => $txId, 'ctrlNum' => $ctrlNum, 'flowId' => $flowId, 'flowCode' => $flowCode];
    }

    public function test_user_without_clearance_cannot_use_forward_to_other_office(): void
    {
        $this->assertFalse(DtsRoutingService::canForwardToOtherOffice($this->userNoClearance));

        $testData = $this->createTestTransaction();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized: You do not have clearance');

        DtsRoutingService::forwardToOtherOffice(
            $testData['txId'],
            $this->officeTarget,
            'Trying to forward without permission',
            $this->userNoClearance->id
        );
    }

    public function test_user_with_regular_clearance_is_authorized(): void
    {
        $this->assertTrue(DtsRoutingService::canForwardToOtherOffice($this->userWithClearance));
    }

    public function test_target_office_must_not_be_same_office_or_placeholder(): void
    {
        $testData = $this->createTestTransaction();

        // 1. Cannot forward to own office
        try {
            DtsRoutingService::forwardToOtherOffice(
                $testData['txId'],
                $this->officeA,
                'Self forward',
                $this->userWithClearance->id
            );
            $this->fail('Expected exception when forwarding to self office.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Target office cannot be your own office', $e->getMessage());
        }

        // 2. Cannot forward to ORIGIN or [H]
        try {
            DtsRoutingService::forwardToOtherOffice(
                $testData['txId'],
                'ORIGIN',
                'Forward to ORIGIN placeholder',
                $this->userWithClearance->id
            );
            $this->fail('Expected exception when forwarding to ORIGIN placeholder.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Invalid target office code', $e->getMessage());
        }
    }

    public function test_forward_to_other_office_forks_shared_flow_and_shifts_ranks(): void
    {
        $testData = $this->createTestTransaction('FLOW-SHARED-TEST');
        $txId = $testData['txId'];

        // Perform forward to ad-hoc target office
        $result = DtsRoutingService::forwardToOtherOffice(
            $txId,
            $this->officeTarget,
            'Forwarding to target office for urgent compliance',
            $this->userWithClearance->id
        );

        $this->assertEquals($this->officeTarget, $result['target_office_code']);
        $this->assertEquals($testData['ctrlNum'], $result['control_number']);

        // 1. Transaction details must point to the new custom flow
        $txDetails = DB::table('dts_transaction_details')->where('id', $txId)->first();
        $this->assertNotNull($txDetails);
        $this->assertStringStartsWith('FLOW-CUSTOM-', $txDetails->transaction_flow);
        $this->assertEquals($this->officeTarget, $txDetails->current_office_hold);

        // 2. Transaction state must point to new office and sequence 2
        $tx = DB::table('dts_transactions')->where('transaction_id', $txId)->first();
        $this->assertEquals($this->officeTarget, $tx->current_office);
        $this->assertEquals(2, $tx->sequence);
        $this->assertEquals('ongoing', $tx->status);

        // 3. Inspect sequence list of new flow
        $newFlow = DB::table('dts_transaction_flow')->where('flow_code', $txDetails->transaction_flow)->first();
        $this->assertNotNull($newFlow);

        $steps = DB::table('dts_sequence_list')
            ->where('control_id', $newFlow->id)
            ->orderBy('sequence_ranking', 'asc')
            ->get();

        $this->assertCount(4, $steps);

        // Step 1: Office A closed out
        $this->assertEquals(1, $steps[0]->sequence_ranking);
        $this->assertEquals($this->officeA, $steps[0]->office_code);
        $this->assertNotNull($steps[0]->date_out);
        $this->assertEquals('To be Forwarded to', $steps[0]->action_needed);
        $this->assertEquals('Forwarding to target office for urgent compliance', $steps[0]->note);
        $this->assertEquals($this->userWithClearance->id, $steps[0]->account_forwarded);

        // Step 2: New inserted step for Office Target
        $this->assertEquals(2, $steps[1]->sequence_ranking);
        $this->assertEquals($this->officeTarget, $steps[1]->office_code);
        $this->assertNull($steps[1]->date_in);
        $this->assertNull($steps[1]->date_out);

        // Step 3: Office B (shifted from sequence 2 to 3)
        $this->assertEquals(3, $steps[2]->sequence_ranking);
        $this->assertEquals($this->officeB, $steps[2]->office_code);

        // Step 4: Office C (shifted from sequence 3 to 4)
        $this->assertEquals(4, $steps[3]->sequence_ranking);
        $this->assertEquals($this->officeC, $steps[3]->office_code);

        // 4. Inspect transaction logs
        $logTable = Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
        $closedLog = DB::table($logTable)
            ->where('transaction_id', $txId)
            ->where('office_code', $this->officeA)
            ->first();
        $this->assertNotNull($closedLog);
        $this->assertNotNull($closedLog->date_out);

        $pendingLog = DB::table($logTable)
            ->where('transaction_id', $txId)
            ->where('office_code', $this->officeTarget)
            ->first();
        $this->assertNotNull($pendingLog);
        $this->assertNull($pendingLog->date_in);
        $this->assertEquals('forwarded', $pendingLog->type);
    }

    public function test_get_available_target_offices_excludes_current_office_and_placeholders(): void
    {
        $available = DtsRoutingService::getAvailableTargetOffices($this->officeA);

        $codes = $available->pluck('office_code')->toArray();
        $this->assertNotContains($this->officeA, $codes);
        $this->assertNotContains('ORIGIN', $codes);
        $this->assertNotContains('[H]', $codes);
        $this->assertNotContains('[HUB]', $codes);
        $this->assertContains($this->officeTarget, $codes);
    }

    public function test_action_options_filtering_by_clearance(): void
    {
        // User without clearance
        Auth::login($this->userNoClearance);
        $this->assertFalse(DtsRoutingService::canForwardToOtherOffice());

        $query1 = DB::table('dts_action_options')->orderBy('option_name', 'asc');
        if (!DtsRoutingService::canForwardToOtherOffice()) {
            $query1->where('option_name', '!=', 'To be Forwarded to');
        }
        $optionsNoClearance = $query1->pluck('option_name')->toArray();
        $this->assertNotContains('To be Forwarded to', $optionsNoClearance);

        // User with clearance
        Auth::login($this->userWithClearance);
        $this->assertTrue(DtsRoutingService::canForwardToOtherOffice());

        $query2 = DB::table('dts_action_options')->orderBy('option_name', 'asc');
        if (!DtsRoutingService::canForwardToOtherOffice()) {
            $query2->where('option_name', '!=', 'To be Forwarded to');
        }
        $optionsWithClearance = $query2->pluck('option_name')->toArray();
        $this->assertContains('To be Forwarded to', $optionsWithClearance);
    }

    public function test_role_permission_model_attributes(): void
    {
        $permission = new role_permission();
        $this->assertContains('can_dts_forward_to_other_office', $permission->getFillable());
        $this->assertEquals('boolean', $permission->getCasts()['can_dts_forward_to_other_office'] ?? null);
    }
}
