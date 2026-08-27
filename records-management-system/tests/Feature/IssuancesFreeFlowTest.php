<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IssuancesFreeFlowTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(1);
        if ($this->user) {
            Auth::login($this->user);
        }

        DB::table('office')->updateOrInsert(
            ['office_code' => 'GENERAL'],
            ['office_name' => 'General Administration Office', 'is_active' => true]
        );
        DB::table('office')->updateOrInsert(
            ['office_code' => 'RFOIU'],
            ['office_name' => 'Records and Freedom of Information Unit', 'is_active' => true]
        );
        DB::table('office')->updateOrInsert(
            ['office_code' => 'RFIO'],
            ['office_name' => 'Records and Freedom of Information Office', 'is_active' => true]
        );
        DB::table('office')->updateOrInsert(
            ['office_code' => 'ICTU'],
            ['office_name' => 'Information and Communications Technology Unit', 'is_active' => true]
        );
        DB::table('office')->updateOrInsert(
            ['office_code' => 'VP'],
            ['office_name' => 'Office of the Vice President', 'is_active' => true]
        );
        DB::table('office')->updateOrInsert(
            ['office_code' => 'CAS'],
            ['office_name' => 'College of Arts and Sciences', 'is_active' => true]
        );
        DB::table('office')->updateOrInsert(
            ['office_code' => '[HUB]'],
            ['office_name' => 'Office Hub [Multi-Receiving]', 'is_active' => true]
        );

        // Ensure a test flow with [HUB] exists
        $testFlow = DB::table('dts_transaction_flow')->where('flow_code', 'FLOW-TEST-HUB')->first();
        if (!$testFlow) {
            $maxFlowId = (DB::table('dts_transaction_flow')->max('id') ?? 0) + 1;
            DB::table('dts_transaction_flow')->insert([
                'id' => $maxFlowId,
                'flow_name' => 'Origin -> [HUB] -> Origin',
                'flow_code' => 'FLOW-TEST-HUB',
                'is_active' => true,
                'flow_use' => 'issuances',
                'flow_for' => 'system',
                'added_by' => 1,
                'date_added' => now(),
            ]);

            DB::table('dts_sequence_list')->insert([
                ['control_id' => $maxFlowId, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN'],
                ['control_id' => $maxFlowId, 'sequence_ranking' => 2, 'office_code' => '[HUB]'],
                ['control_id' => $maxFlowId, 'sequence_ranking' => 3, 'office_code' => 'ORIGIN'],
            ]);
        }
    }

    public function test_flow_with_hub_enables_hub_mode()
    {
        $comp = Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB');

        $this->assertTrue($comp->get('hasHub'));
    }

    public function test_adding_receiving_office_auto_adds_to_copy_furnished_and_removal_is_independent()
    {
        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->call('selectFreeFlowOffice', 'ICTU')
            ->assertSet('free_flow_receiving_offices', ['ICTU'])
            ->assertSet('cf_selected_offices', ['ICTU'])
            ->call('selectFreeFlowOffice', 'VP')
            ->assertSet('free_flow_receiving_offices', ['ICTU', 'VP'])
            ->assertSet('cf_selected_offices', ['ICTU', 'VP'])
            // Remove from Copy Furnished only: Receiving offices must remain ['ICTU', 'VP']
            ->call('removeCfOffice', 0)
            ->assertSet('cf_selected_offices', ['VP'])
            ->assertSet('free_flow_receiving_offices', ['ICTU', 'VP'])
            // Remove from Receiving offices only: Copy furnished must remain ['VP']
            ->call('removeFreeFlowOffice', 1)
            ->assertSet('free_flow_receiving_offices', ['ICTU'])
            ->assertSet('cf_selected_offices', ['VP']);
    }

    public function test_adding_cf_office_does_not_alter_hub_receiving_offices()
    {
        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->call('selectFreeFlowOffice', 'ICTU')
            ->assertSet('free_flow_receiving_offices', ['ICTU'])
            ->assertSet('cf_selected_offices', ['ICTU'])
            // Adding CAS to Copy Furnished must NOT add CAS to HUB
            ->call('selectCfOffice', 'CAS')
            ->assertSet('cf_selected_offices', ['ICTU', 'CAS'])
            ->assertSet('free_flow_receiving_offices', ['ICTU']);
    }

    public function test_all_offices_on_hub_syncs_to_cf_but_all_offices_on_cf_does_not_alter_hub()
    {
        // 1. All offices on Hub syncs to Copy Furnished
        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->call('selectAllReceivingOffices')
            ->assertSet('free_flow_receiving_offices', ['ALL'])
            ->assertSet('cf_selected_offices', ['ALL']);

        // 2. All offices on Copy Furnished does NOT alter Hub
        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->call('selectFreeFlowOffice', 'ICTU')
            ->assertSet('free_flow_receiving_offices', ['ICTU'])
            ->assertSet('cf_selected_offices', ['ICTU'])
            ->call('selectAllCfOffices')
            ->assertSet('cf_selected_offices', ['ALL'])
            ->assertSet('free_flow_receiving_offices', ['ICTU']);
    }

    public function test_hub_issuance_creates_multi_office_broadcast_and_logs()
    {
        $testSubject = 'Hub Routing Test Memo - ' . uniqid();

        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->set('issuance_type', 'NM')
            ->set('subject', $testSubject)
            ->call('selectFreeFlowOffice', 'ICTU')
            ->call('selectFreeFlowOffice', 'VP')
            ->set('copy_furnished', 'Yes')
            ->call('selectCfOffice', 'CAS')
            ->call('save')
            ->assertHasNoErrors();

        // Verify primary transaction exists in dts_transaction_details for ICTU
        $transDetail = DB::table('dts_transaction_details')
            ->where('subject', $testSubject)
            ->whereRaw("control_number NOT LIKE '%-1'")
            ->first();

        $this->assertNotNull($transDetail);

        // Verify child transaction exists for VP with suffix -1
        $childTrans = DB::table('dts_transaction_details')
            ->where('subject', $testSubject)
            ->where('control_number', $transDetail->control_number . '-1')
            ->first();

        $this->assertNotNull($childTrans);

        // Verify child transaction has valid Hacore QR code registered in dts_qr_code
        $childTransRecord = DB::table('dts_transactions')->where('transaction_id', $childTrans->id)->first();
        $this->assertNotNull($childTransRecord);
        $this->assertNotNull($childTransRecord->qr_code);

        $qrRecord = DB::table('dts_qr_code')->where('code_id', $childTransRecord->qr_code)->first();
        $this->assertNotNull($qrRecord);

        // Verify tracking logs exist for ICTU on parent and VP on child
        $parentLogs = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $transDetail->id)
            ->pluck('office_code')
            ->toArray();
        $this->assertContains('ICTU', $parentLogs);

        $childLogs = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $childTrans->id)
            ->pluck('office_code')
            ->toArray();
        $this->assertContains('VP', $childLogs);
    }

    public function test_my_transactions_groups_child_branches_with_collapsible_dropdown()
    {
        $testSubject = 'Hub Grouping Test Memo - ' . uniqid();

        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->set('issuance_type', 'NM')
            ->set('subject', $testSubject)
            ->call('selectFreeFlowOffice', 'ICTU')
            ->call('selectFreeFlowOffice', 'VP')
            ->call('save')
            ->assertHasNoErrors();

        $transDetail = DB::table('dts_transaction_details')
            ->where('subject', $testSubject)
            ->whereRaw("control_number NOT LIKE '%-1'")
            ->first();

        $this->assertNotNull($transDetail);

        // Test My Transactions grouping and dropdown toggle
        $comp = Volt::test('pages.dts.my-transactions')
            ->set('searchQuery', $transDetail->control_number)
            ->call('toggleExpandHub', $transDetail->control_number);

        $this->assertContains($transDetail->control_number, $comp->get('expandedHubTransactions'));

        // Toggle again to collapse
        $comp->call('toggleExpandHub', $transDetail->control_number);
        $this->assertNotContains($transDetail->control_number, $comp->get('expandedHubTransactions'));
    }

    public function test_receiving_office_can_receive_hub_issuance_in_incoming()
    {
        $testSubject = 'Hub Receive Test - ' . uniqid();

        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->set('issuance_type', 'OM')
            ->set('subject', $testSubject)
            ->call('selectFreeFlowOffice', 'ICTU')
            ->call('save')
            ->assertHasNoErrors();

        $transDetail = DB::table('dts_transaction_details')
            ->where('subject', $testSubject)
            ->first();

        $this->assertNotNull($transDetail);

        // Test receiving the incoming document
        $userOfficeCode = auth()->user()?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode(auth()->user());
        
        // Ensure user office has a pending log
        DB::table('sub_document_tracking_system_logs')->updateOrInsert(
            ['transaction_id' => $transDetail->id, 'office_code' => $userOfficeCode],
            ['type' => 'forwarded', 'date_in' => null, 'date_out' => null]
        );

        Volt::test('pages.dts.incoming')
            ->call('receiveIncoming', $transDetail->id)
            ->assertHasNoErrors();

        $receivedLog = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $transDetail->id)
            ->where('office_code', $userOfficeCode)
            ->first();

        $this->assertNotNull($receivedLog);
        $this->assertEquals('received', $receivedLog->type);
        $this->assertNotNull($receivedLog->date_in);
    }

    public function test_hub_office_receiving_and_forwarding_notifies_origin()
    {
        $testSubject = 'Hub Notification Test - ' . uniqid();

        // 1. Create Issuance with [HUB]
        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', 'FLOW-TEST-HUB')
            ->set('issuance_type', 'NM')
            ->set('subject', $testSubject)
            ->call('selectFreeFlowOffice', 'ICTU')
            ->call('save')
            ->assertHasNoErrors();

        $transDetail = DB::table('dts_transaction_details')
            ->where('subject', $testSubject)
            ->first();

        $this->assertNotNull($transDetail);
        $originOffice = $transDetail->originated_from ?: 'ORIGIN';

        // 2. Trigger notification for ICTU receiving the transaction
        \App\Services\DtsNotificationService::notifyHubOfficeReceived(
            $originOffice,
            'ICTU',
            $transDetail->control_number,
            $transDetail->id
        );

        // Check if notification exists for origin office
        $notif = DB::table('notifications')
            ->join('notif_content', 'notif_content.id', '=', 'notifications.contents')
            ->where('notifications.office', $originOffice)
            ->where('notif_content.content', 'like', '%has received Transaction ' . $transDetail->control_number . '%')
            ->first();

        $this->assertNotNull($notif);

        // 3. Trigger notification for ICTU completing and forwarding the transaction
        \App\Services\DtsNotificationService::notifyHubOfficeForwarded(
            $originOffice,
            'ICTU',
            $transDetail->control_number,
            $transDetail->id,
            'ICTU Officer'
        );

        // Check if forward notification exists for origin office
        $fwdNotif = DB::table('notifications')
            ->join('notif_content', 'notif_content.id', '=', 'notifications.contents')
            ->where('notifications.office', $originOffice)
            ->where('notif_content.content', 'like', '%has completed and forwarded Transaction ' . $transDetail->control_number . '%')
            ->first();

        $this->assertNotNull($fwdNotif);
    }

    public function test_predefined_hub_offices_saved_and_autoloaded_in_create_issuances()
    {
        $flowCode = strtoupper('FLOW-PREDEF-HUB-' . uniqid());
        $flowName = 'Predefined Hub Test Flow ' . uniqid();

        // 1. Admin creates predefined flow with [HUB] and hubOffices
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'new')
            ->set('flowName', $flowName)
            ->set('flowCode', $flowCode)
            ->set('flowUse', 'issuances')
            ->set('flowOffices', ['ORIGIN', '[HUB]', 'ORIGIN'])
            ->set('hubOffices', ['ICTU', 'CAS'])
            ->call('savePredefinedFlow')
            ->assertHasNoErrors();

        // Verify flow and hub_flow_datas exist in DB
        $flow = DB::table('dts_transaction_flow')->where('flow_code', $flowCode)->first();
        $this->assertNotNull($flow);

        $savedHubOffices = DB::table('hub_flow_datas')
            ->where('flow_owner', $flow->id)
            ->pluck('offices_hub')
            ->toArray();
        $this->assertEqualsCanonicalizing(['ICTU', 'CAS'], $savedHubOffices);

        // 2. User selects this flow in create/issuances
        Volt::test('pages.dts.create.issuances')
            ->set('transaction_flow', $flowCode)
            ->assertSet('free_flow_receiving_offices', ['ICTU', 'CAS'])
            ->assertSet('cf_selected_offices', ['ICTU', 'CAS']);
    }

    public function test_custom_flow_with_hub_saves_to_hub_flow_datas()
    {
        $customDocType = 'Custom Hub Memo ' . uniqid();

        // User creates custom flow in create/issuances
        Volt::test('pages.dts.create.issuances')
            ->call('openCustomFlowCreator')
            ->set('customFlowDocType', $customDocType)
            ->set('customFlowSequence', ['ORIGIN', '[HUB]', 'ORIGIN'])
            ->set('customFlowHubOffices', ['VP', 'CAS'])
            ->call('saveCustomFlow')
            ->assertHasNoErrors();

        $flow = DB::table('dts_transaction_flow')->where('flow_name', $customDocType)->first();
        $this->assertNotNull($flow);

        $savedHubOffices = DB::table('hub_flow_datas')
            ->where('flow_owner', $flow->id)
            ->pluck('offices_hub')
            ->toArray();
        $this->assertEqualsCanonicalizing(['VP', 'CAS'], $savedHubOffices);
    }
}


