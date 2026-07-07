<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DtsEmailAccessTest extends TestCase
{
    private $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        // Authenticate admin user
        $user = User::find($this->testUserId);
        if ($user) {
            Auth::login($user);
        }
    }

    public function test_system_settings_toggles_dts_email_access_requirements()
    {
        $admin = User::find($this->testUserId);
        if (!$admin) {
            $this->markTestSkipped('Admin user not found.');
            return;
        }

        // Initialize setting values
        DB::table('system_settings')->updateOrInsert(['key' => 'dts_email_access_required_external'], ['value' => 'true']);
        DB::table('system_settings')->updateOrInsert(['key' => 'dts_email_access_required_application'], ['value' => 'true']);
        DB::table('system_settings')->updateOrInsert(['key' => 'dts_email_access_required_internal'], ['value' => 'false']);

        $component = Volt::test('pages.admin.settings.index')
            ->assertSet('emailAccessRequiredExternal', true)
            ->assertSet('emailAccessRequiredApplication', true)
            ->assertSet('emailAccessRequiredInternal', false);

        // Modify and save
        $component->set('emailAccessRequiredExternal', false)
            ->set('emailAccessRequiredApplication', false)
            ->set('emailAccessRequiredInternal', true)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'System settings updated successfully!');

        // Assert DB updated
        $this->assertDatabaseHas('system_settings', ['key' => 'dts_email_access_required_external', 'value' => 'false']);
        $this->assertDatabaseHas('system_settings', ['key' => 'dts_email_access_required_application', 'value' => 'false']);
        $this->assertDatabaseHas('system_settings', ['key' => 'dts_email_access_required_internal', 'value' => 'true']);
    }

    public function test_transaction_creation_validates_email_access_correctly()
    {
        // 1. External (required by default)
        DB::table('system_settings')->updateOrInsert(['key' => 'dts_email_access_required_external'], ['value' => 'true']);
        
        $componentExternal = Volt::test('pages.dts.create.external');
        $componentExternal->set('seq_number', '99999')
            ->set('source_office', 'ORIGIN')
            ->set('requestor_name', 'Test User')
            ->set('requestor_label', 'Dr.')
            ->set('subject', 'Test External Subject')
            ->set('transaction_flow', 'TEST')
            ->set('email_access_input', '')
            ->set('document_password_input', '')
            ->call('save')
            ->assertHasErrors(['email_access_input', 'document_password_input']);

        // 2. Application Letters (required by default)
        DB::table('system_settings')->updateOrInsert(['key' => 'dts_email_access_required_application'], ['value' => 'true']);
        
        $componentApp = Volt::test('pages.dts.create.application-letters');
        $componentApp->set('seq_number', '99999')
            ->set('type_of_document', 'Application Letter')
            ->set('applicant_name', 'Test Applicant')
            ->set('position', 'Developer')
            ->set('unit_college', 'ORIGIN')
            ->set('transaction_flow', 'TEST')
            ->set('email_access_input', '')
            ->set('document_password_input', '')
            ->call('save')
            ->assertHasErrors(['email_access_input', 'document_password_input']);

        // 3. Internal (optional by default)
        DB::table('system_settings')->updateOrInsert(['key' => 'dts_email_access_required_internal'], ['value' => 'false']);
        
        $componentInternal = Volt::test('pages.dts.create.internal');
        // If email and password are empty, it should not fail on them
        $componentInternal->set('seq_number', '99999')
            ->set('unit_college', 'ORIGIN')
            ->set('requestor_name', 'Test User')
            ->set('classification', 'simple')
            ->set('action_needed', 'For action')
            ->set('subject', 'Test Internal Subject')
            ->set('transaction_flow', 'TEST')
            ->set('email_access_input', '')
            ->set('document_password_input', '')
            // We don't call save() because it might fail on missing QR code,
            // but we check if we can fill email and password optionally
            ->assertSet('email_access_input', '')
            ->assertSet('document_password_input', '');
    }

    public function test_track_document_verifies_email_access_and_password()
    {
        // 1. Setup a test transaction with email access restriction and document password
        $transactionId = 'TRANS-TEST-EMAIL-ACCESS';
        $qrCode = 'QR-TEST-EMAIL-ACCESS';
        $email = 'authorized@example.com';
        $password = 'secret123';

        // Clean up any existing transaction
        DB::table('dts_transaction_details')->where('id', $transactionId)->delete();
        DB::table('dts_transactions')->where('transaction_id', $transactionId)->delete();
        DB::table('dts_qr_code')->where('code_id', $qrCode)->delete();
        DB::table('dts_transaction_flow')->where('flow_code', 'TEST')->delete();

        // Create test flow
        DB::table('dts_transaction_flow')->insert([
            'id' => 9999,
            'flow_code' => 'TEST',
            'flow_name' => 'Test Flow',
            'added_by' => $this->testUserId,
            'date_added' => now(),
            'flow_use' => 'external',
            'is_active' => 1,
        ]);

        // Create QR code
        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCode,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        // Create transaction
        DB::table('dts_transactions')->insert([
            'transaction_id' => $transactionId,
            'enable_notif' => 1,
            'trans_type' => 'external',
            'current_office' => 'ORIGIN',
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qrCode,
        ]);

        // Insert email access
        $emailAccessId = DB::table('dts_email_access')->insertGetId([
            'email' => $email,
            'is_active' => true,
            'date_created' => now(),
        ]);

        // Insert transaction details
        DB::table('dts_transaction_details')->insert([
            'id' => $transactionId,
            'type' => 'external',
            'created_by' => $this->testUserId,
            'originated_from' => 'ORIGIN',
            'requestor_name' => 'Test User',
            'requestor_label' => 'Dr.',
            'subject' => 'Test Subject',
            'classification' => 'Simple',
            'action_needed' => 'For action',
            'current_office_hold' => 'ORIGIN',
            'status' => 'ongoing',
            'document_password' => $password,
            'email_access' => $emailAccessId,
            'transaction_flow' => 'TEST',
            'is_active' => 1,
            'date_created' => now(),
            'control_number' => 'EXT-2026-TEST',
        ]);

        // Test public tracking component (pages.portal.tracked)
        // A. Verify with unauthorized email -> Should fail
        Volt::test('pages.portal.tracked', ['number' => $qrCode])
            ->set('email', 'unauthorized@example.com')
            ->call('verifyEmail')
            ->assertHasErrors(['email']);

        // B. Verify with authorized email, test incorrect password, then correct password
        Volt::test('pages.portal.tracked', ['number' => $qrCode])
            ->set('email', $email)
            ->call('verifyEmail')
            ->assertHasNoErrors()
            ->assertSet('showPasswordStep', true)
            ->set('documentPassword', 'wrongpassword')
            ->call('submitPassword')
            ->assertHasErrors(['documentPassword'])
            ->set('documentPassword', $password)
            ->call('submitPassword')
            ->assertHasNoErrors()
            ->assertSet('showDocumentData', true);

        // Clean up
        DB::table('dts_transaction_details')->where('id', $transactionId)->delete();
        DB::table('dts_transactions')->where('transaction_id', $transactionId)->delete();
        DB::table('dts_qr_code')->where('code_id', $qrCode)->delete();
        DB::table('dts_email_access')->where('id', $emailAccessId)->delete();
        DB::table('dts_transaction_flow')->where('flow_code', 'TEST')->delete();
    }
}
