<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateTransactionQrCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Authenticate admin user (ID 1)
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
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

        // Ensure flow exists
        $existing = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-CREATE')->first();
        if (!$existing) {
            $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
            $newFlowId = $maxId + 1;
            DB::table('dts_transaction_flow')->insert([
                'id' => $newFlowId,
                'flow_code' => 'TEST-FLOW-CREATE',
                'flow_name' => 'Test Flow Create',
                'is_active' => true
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
        parent::tearDown();
    }

    public function test_qr_code_must_be_generated_before_creating_transaction()
    {
        // 1. Trying to save without generating QR code fails
        Volt::test('pages.dts.create.internal')
            ->set('seq_number', '9999')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Test User')
            ->set('type_of_document', 'test-doc')
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
            ->set('type_of_document', 'test-doc')
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
}
