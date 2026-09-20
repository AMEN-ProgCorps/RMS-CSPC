<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TransactionFlowRestrictorTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(1);
        if ($this->user) {
            Auth::login($this->user);
        }

        $settingsTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        $officeTable = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        // Disable email access requirements during test
        DB::table($settingsTable)->updateOrInsert(['key' => 'dts_email_access_required_external'], ['value' => 'false']);
        DB::table($settingsTable)->updateOrInsert(['key' => 'dts_email_access_required_application'], ['value' => 'false']);
        DB::table($settingsTable)->updateOrInsert(['key' => 'dts_email_access_required_internal'], ['value' => 'false']);

        // Ensure ORIGIN and test offices exist
        DB::table($officeTable)->updateOrInsert(
            ['office_code' => 'ORIGIN'],
            ['office_name' => 'Originated Office', 'is_active' => true]
        );
        DB::table($officeTable)->updateOrInsert(
            ['office_code' => 'TEST-MID'],
            ['office_name' => 'Middle Office', 'is_active' => true]
        );
        DB::table($officeTable)->updateOrInsert(
            ['office_code' => 'OTHER-OFF'],
            ['office_name' => 'Other Office', 'is_active' => true]
        );

        // Ensure valid test flow exists
        $existing = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-VALID')->first();
        if (!$existing) {
            $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
            $newFlowId = $maxId + 1;
            DB::table('dts_transaction_flow')->insert([
                'id' => $newFlowId,
                'flow_code' => 'TEST-FLOW-VALID',
                'flow_name' => 'Test Flow Valid 3 Nodes',
                'is_active' => true,
                'added_by' => 1,
                'date_added' => now(),
                'flow_use' => 'none'
            ]);
            $existing = DB::table('dts_transaction_flow')->where('id', $newFlowId)->first();
        }

        DB::table('dts_sequence_list')->where('control_id', $existing->id)->delete();
        DB::table('dts_sequence_list')->insert([
            ['control_id' => $existing->id, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN'],
            ['control_id' => $existing->id, 'sequence_ranking' => 2, 'office_code' => 'TEST-MID'],
            ['control_id' => $existing->id, 'sequence_ranking' => 3, 'office_code' => 'ORIGIN'],
        ]);
    }

    public function test_internal_fails_when_flow_has_only_one_office()
    {
        $qrCode = 'QR-TST-FL-01';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '1001')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Flow Test User')
            ->set('type_of_document', 'Test Flow')
            ->set('action_needed', 'For approval')
            ->set('subject', 'Test 1 Office Subject')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow being only has 1 office, on which is insufficient to be a transaction.'])
            ->assertSet('toastMessage', 'Incomplete transaction flow being only has 1 office, on which is insufficient to be a transaction.');
    }

    public function test_internal_fails_when_flow_has_only_two_offices()
    {
        $qrCode = 'QR-TST-FL-02';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '1002')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Flow Test User')
            ->set('type_of_document', 'Test Flow')
            ->set('action_needed', 'For approval')
            ->set('subject', 'Test 2 Office Subject')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN', 'TEST-MID'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow being only has 2 offices, on which is insufficient to be a transaction.'])
            ->assertSet('toastMessage', 'Incomplete transaction flow being only has 2 offices, on which is insufficient to be a transaction.');
    }

    public function test_internal_fails_when_starter_office_is_not_origin()
    {
        $qrCode = 'QR-TST-FL-03';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '1003')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Flow Test User')
            ->set('type_of_document', 'Test Flow')
            ->set('action_needed', 'For approval')
            ->set('subject', 'Test Invalid Starter')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['OTHER-OFF', 'TEST-MID', 'ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow - The starter office must be the ORIGIN office.'])
            ->assertSet('toastMessage', 'Incomplete transaction flow - The starter office must be the ORIGIN office.');
    }

    public function test_internal_fails_when_final_office_is_not_origin()
    {
        $qrCode = 'QR-TST-FL-04';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '1004')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Flow Test User')
            ->set('type_of_document', 'Test Flow')
            ->set('action_needed', 'For approval')
            ->set('subject', 'Test Invalid Final')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN', 'TEST-MID', 'OTHER-OFF'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow - The final office must also be the ORIGIN office.'])
            ->assertSet('toastMessage', 'Incomplete transaction flow - The final office must also be the ORIGIN office.');
    }

    public function test_internal_fails_when_no_intermediate_offices_between_origins()
    {
        $qrCode = 'QR-TST-FL-05';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '1005')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Flow Test User')
            ->set('type_of_document', 'Test Flow')
            ->set('action_needed', 'For approval')
            ->set('subject', 'Test No Intermediate')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN', 'ORIGIN', 'ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow - There must be an office or offices in between both ORIGIN offices.'])
            ->assertSet('toastMessage', 'Incomplete transaction flow - There must be an office or offices in between both ORIGIN offices.');
    }

    public function test_internal_succeeds_when_flow_is_valid()
    {
        $qrCode = 'QR-TST-FL-06';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '1006')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Flow Test User')
            ->set('type_of_document', 'Test Flow')
            ->set('action_needed', 'For approval')
            ->set('subject', 'Test Valid Flow Subject')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN', 'TEST-MID', 'ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_external_fails_when_flow_has_insufficient_offices()
    {
        $qrCode = 'QR-TST-FL-EXT-01';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.external')
            ->set('seq_number', '2001')
            ->set('source_office', 'ORIGIN')
            ->set('requestor_name', 'External User')
            ->set('requestor_label', 'Manager')
            ->set('subject', 'Test External Insufficient Flow')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN', 'ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow being only has 2 offices, on which is insufficient to be a transaction.']);
    }

    public function test_application_letters_fails_when_flow_has_insufficient_offices()
    {
        $qrCode = 'QR-TST-FL-APL-01';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.application-letters')
            ->set('seq_number', '3001')
            ->set('applicant_name', 'Applicant User')
            ->set('position', 'Instructor')
            ->set('unit_college', 'ORIGIN')
            ->set('type_of_document', 'Application Form')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow being only has 1 office, on which is insufficient to be a transaction.']);
    }

    public function test_issuances_fails_when_flow_has_insufficient_offices()
    {
        $qrCode = 'QR-TST-FL-ISS-01';
        DB::table('dts_qr_code')->updateOrInsert(
            ['code_id' => $qrCode],
            ['qr_status' => 'not used', 'created_at' => now()]
        );

        Volt::test('pages.dts.create.issuances')
            ->set('seq_number', '4001')
            ->set('issuance_type', 'NM')
            ->set('subject', 'Test Issuance Insufficient Flow')
            ->set('transaction_flow', 'TEST-FLOW-VALID')
            ->set('flow_offices', ['ORIGIN', 'ORIGIN'])
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCode)
            ->call('save')
            ->assertHasErrors(['transaction_flow' => 'Incomplete transaction flow being only has 2 offices, on which is insufficient to be a transaction.']);
    }
}
