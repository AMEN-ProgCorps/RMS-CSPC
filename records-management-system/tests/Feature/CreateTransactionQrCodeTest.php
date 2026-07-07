<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateTransactionQrCodeTest extends TestCase
{
    protected $user;
    protected $rolePermissionId;

    protected function setUp(): void
    {
        parent::setUp();

        // Authenticate admin user (ID 1)
        $this->user = User::find(1);
        if ($this->user) {
            Auth::login($this->user);
            $this->rolePermissionId = $this->user->account_role;
        }

        // Clean up test records
        DB::table('dts_transactions')->where('doc_dir', 'like', '%test-doc%')->delete();
        DB::table('dts_transaction_details')->where('subject', 'Test Subject')->delete();
        DB::table('dts_qr_code')->where('code_id', 'like', 'QR-TST-%')->delete();

        // Ensure ORIGIN exists
        DB::table('office')->updateOrInsert(
            ['office_code' => 'ORIGIN'],
            ['office_name' => 'Originated Office', 'is_active' => true]
        );

        // Ensure a second test office exists for custom flow tests
        DB::table('office')->updateOrInsert(
            ['office_code' => 'TST-OFF'],
            ['office_name' => 'Test Office', 'is_active' => true]
        );

        // Ensure flow exists
        $existing = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-CREATE')->first();
        if (!$existing) {
            $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
            $newFlowId = $maxId + 1;
            DB::table('dts_transaction_flow')->insert([
                'id' => $newFlowId,
                'flow_code' => 'TEST-FLOW-CREATE',
                'flow_name' => 'Test Flow Create',
                'is_active' => true,
                'added_by' => 1,
                'date_added' => now(),
                'flow_use' => 'none'
            ]);
            $existing = DB::table('dts_transaction_flow')->where('id', $newFlowId)->first();
        }

        DB::table('dts_sequence_list')->updateOrInsert(
            ['control_id' => $existing->id, 'sequence_ranking' => 1],
            ['office_code' => 'ORIGIN']
        );
    }

    protected function tearDown(): void
    {
        DB::table('dts_transactions')->where('doc_dir', 'like', '%test-doc%')->delete();
        DB::table('dts_transaction_details')->where('subject', 'Test Subject')->delete();
        DB::table('dts_qr_code')->where('code_id', 'like', 'QR-TST-%')->delete();
        DB::table('dts_transaction_flow')->where('flow_name', 'Custom Test Document')->delete();

        // Restore admin permissions
        if ($this->rolePermissionId) {
            DB::table('condition_details')->where('key_id', $this->rolePermissionId)->update([
                'is_sadm' => true,
                'can_dts_create_own_flow' => true
            ]);
        }

        parent::tearDown();
    }

    public function test_qr_code_must_be_generated_before_creating_transaction()
    {
        // 1. Trying to save without generating QR code fails
        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '9999')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Test User')
            ->set('type_of_document', 'Test Flow Create')
            ->set('classification', 'simple')
            ->set('subject', 'Test Subject')
            ->set('action_needed', 'For approval')
            ->set('transaction_flow', 'TEST-FLOW-CREATE')
            ->set('copy_furnished', 'No')
            ->call('save')
            ->assertHasErrors(['seq_number' => 'Please generate a QR Code first.']);

        // 2. Generate QR code
        $component = Volt::test('pages.dts.create.internal')
            ->set('seq_number', '9999')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Test User')
            ->set('type_of_document', 'Test Flow Create')
            ->set('classification', 'simple')
            ->set('subject', 'Test Subject')
            ->set('action_needed', 'For approval')
            ->set('transaction_flow', 'TEST-FLOW-CREATE')
            ->set('copy_furnished', 'No');

        $component->call('generateQrCode');

        $qrCode = $component->get('generatedQrCode');
        $this->assertNotNull($qrCode);
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $qrCode);

        // Verify it was registered as not used in DB
        $this->assertDatabaseHas('dts_qr_code', [
            'code_id' => $qrCode,
            'qr_status' => 'not used'
        ]);

        // 3. Save should now succeed
        $component->call('save')
            ->assertHasNoErrors();

        // Verify it was marked used in DB after save
        $this->assertDatabaseHas('dts_qr_code', [
            'code_id' => $qrCode,
            'qr_status' => 'used'
        ]);

        // Verify transaction was created in DB
        $this->assertDatabaseHas('dts_transactions', [
            'qr_code' => $qrCode
        ]);
    }

    public function test_requestor_label_validation()
    {
        // 1. In internal, requestor_label is optional
        $qrCodeInternal = 'QR-TST-INT-LBL';
        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCodeInternal,
            'qr_status' => 'not used',
            'created_at' => now(),
        ]);

        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '9999')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Test User')
            ->set('type_of_document', 'Test Flow Create')
            ->set('classification', 'simple')
            ->set('subject', 'Test Subject')
            ->set('action_needed', 'For approval')
            ->set('transaction_flow', 'TEST-FLOW-CREATE')
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCodeInternal)
            ->set('requestor_label', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dts_transaction_details', [
            'requestor_name' => 'Test User',
            'requestor_label' => '',
        ]);

        // 2. In external, requestor_label is required
        $qrCodeExternal = 'QR-TST-EXT-LBL';
        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCodeExternal,
            'qr_status' => 'not used',
            'created_at' => now(),
        ]);

        Volt::test('pages.dts.create.external')
            ->set('seq_number', '9999')
            ->set('source_office', 'ORIGIN')
            ->set('requestor_name', 'Test External User')
            ->set('subject', 'Test Subject')
            ->set('transaction_flow', 'TEST-FLOW-CREATE')
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCodeExternal)
            ->set('requestor_label', '') // empty but required
            ->call('save')
            ->assertHasErrors(['requestor_label' => 'required']);

        // Now set requestor_label
        Volt::test('pages.dts.create.external')
            ->set('seq_number', '9999')
            ->set('source_office', 'ORIGIN')
            ->set('requestor_name', 'Test External User')
            ->set('subject', 'Test Subject')
            ->set('transaction_flow', 'TEST-FLOW-CREATE')
            ->set('copy_furnished', 'No')
            ->set('generatedQrCode', $qrCodeExternal)
            ->set('requestor_label', 'Manager')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('dts_transaction_details', [
            'requestor_name' => 'Test External User',
            'requestor_label' => 'Manager',
        ]);
    }

    public function test_custom_flow_creation_and_permissions()
    {
        $user = DB::table('account')->where('id', $this->user->id)->first();
        
        // Disable permission first
        DB::table('condition_details')->where('key_id', $this->rolePermissionId)->update([
            'is_sadm' => false,
            'can_dts_create_own_flow' => false
        ]);

        // 1. Without permission, openCustomFlowCreator sets error message and modal remains closed
        Volt::test('pages.dts.create.internal')
            ->call('openCustomFlowCreator')
            ->assertSet('showCustomFlowModal', false)
            ->assertSet('toastMessage', 'Your account does not have permission to create its own transaction flow.');

        // 2. Grant permission
        DB::table('condition_details')->where('key_id', $this->rolePermissionId)->update([
            'can_dts_create_own_flow' => true
        ]);

        // Refresh the authenticated user so cached permissions are cleared
        $freshUser = User::find($this->user->id);
        Auth::setUser($freshUser);

        // 3. With permission, modal opens and can create a custom flow
        $component = Volt::test('pages.dts.create.internal')
            ->call('openCustomFlowCreator')
            ->assertSet('showCustomFlowModal', true)
            ->assertSet('toastMessage', '')
            ->set('customFlowDocType', 'Custom Test Document')
            ->set('customFlowSelectedOffice', 'ORIGIN')
            ->call('addToCustomFlowSequence')
            ->assertSet('customFlowSequence', ['ORIGIN'])
            ->set('customFlowSelectedOffice', 'TST-OFF')
            ->call('addToCustomFlowSequence')
            ->assertSet('customFlowSequence', ['ORIGIN', 'TST-OFF'])
            ->call('saveCustomFlow')
            ->assertHasNoErrors();

        // 4. Assert flow is registered in dts_transaction_flow and visible to user
        $this->assertDatabaseHas('dts_transaction_flow', [
            'flow_name' => 'Custom Test Document',
            'flow_use' => 'internal',
            'added_by' => $user->id
        ]);
    }
}
