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

        // Ensure test offices exist
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
    }

    public function test_can_toggle_between_free_flow_and_linear_modes()
    {
        Volt::test('pages.dts.create.issuances')
            ->assertSet('flow_mode', 'free_flow')
            ->call('setFlowMode', 'linear')
            ->assertSet('flow_mode', 'linear')
            ->call('setFlowMode', 'free_flow')
            ->assertSet('flow_mode', 'free_flow');
    }

    public function test_adding_receiving_office_auto_adds_to_copy_furnished_and_removal_is_independent()
    {
        Volt::test('pages.dts.create.issuances')
            ->set('flow_mode', 'free_flow')
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

    public function test_free_flow_issuance_creates_multi_office_broadcast_and_logs()
    {
        $testSubject = 'Free Flow Test Memo - ' . uniqid();

        $component = Volt::test('pages.dts.create.issuances')
            ->set('flow_mode', 'free_flow')
            ->set('issuance_type', 'NM')
            ->set('subject', $testSubject)
            ->call('selectFreeFlowOffice', 'ICTU')
            ->call('selectFreeFlowOffice', 'VP')
            ->set('copy_furnished', 'Yes')
            ->call('selectCfOffice', 'CAS')
            ->call('save')
            ->assertHasNoErrors();

        // Verify transaction exists in dts_transaction_details
        $transDetail = DB::table('dts_transaction_details')
            ->where('subject', $testSubject)
            ->first();

        $this->assertNotNull($transDetail);
        $this->assertEquals('FLOW-FREE-FLOW', $transDetail->transaction_flow);

        // Verify tracking logs exist for ICTU, VP, and CAS
        $logs = DB::table('sub_document_tracking_system_logs')
            ->where('transaction_id', $transDetail->id)
            ->pluck('office_code')
            ->toArray();

        $this->assertContains('ICTU', $logs);
        $this->assertContains('VP', $logs);
        $this->assertContains('CAS', $logs);
    }

    public function test_receiving_office_can_receive_free_flow_issuance_in_incoming()
    {
        $testSubject = 'Free Flow Receive Test - ' . uniqid();

        Volt::test('pages.dts.create.issuances')
            ->set('flow_mode', 'free_flow')
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
        $userOfficeCode = auth()->user()?->details?->office?->office_code ?? 'ICTU';
        
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
}
