<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DtsDashboardFilteringTest extends TestCase
{
    private $testUserId = 1;
    private $myOfficeId;
    private $myOfficeCode = 'MY_OFFICE';
    private $otherOfficeId;
    private $otherOfficeCode = 'OTHER_OFFICE';
    private $qrCodes = [];
    private $flowId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Authenticate user
        $user = User::find($this->testUserId);
        if (!$user) {
            $this->markTestSkipped('Testing user does not exist.');
            return;
        }
        Auth::login($user);

        // 2. Set up test offices
        $this->myOfficeId = DB::table('office')->insertGetId([
            'office_name' => 'My Test Office',
            'office_code' => $this->myOfficeCode,
        ]);

        $this->otherOfficeId = DB::table('office')->insertGetId([
            'office_name' => 'Other Test Office',
            'office_code' => $this->otherOfficeCode,
        ]);

        // Link the authenticated user to my test office
        DB::table('account_details')
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => $this->myOfficeId]);

        // Ensure the flow 'TEST' exists
        $existing = DB::table('dts_transaction_flow')->where('flow_code', 'TEST')->first();
        if ($existing) {
            $this->flowId = $existing->id;
        } else {
            $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
            $this->flowId = DB::table('dts_transaction_flow')->insertGetId([
                'id' => $maxId + 1,
                'flow_code' => 'TEST',
                'flow_name' => 'Test Flow',
                'is_active' => true,
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Restore account details
        DB::table('account_details')
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => null]);

        // Cleanup test transactions and offices
        $transIds = DB::table('dts_transaction_details')
            ->where('subject', 'like', 'TST-SUB-%')
            ->pluck('id')
            ->toArray();

        if (!empty($transIds)) {
            DB::table('sub_document_tracking_system_logs')->whereIn('transaction_id', $transIds)->delete();
            DB::table('dts_transaction_details')->whereIn('id', $transIds)->delete();
            DB::table('dts_transactions')->whereIn('transaction_id', $transIds)->delete();
        }

        if (!empty($this->qrCodes)) {
            DB::table('dts_qr_code')->whereIn('code_id', $this->qrCodes)->delete();
        }

        DB::table('dts_transaction_flow')->where('flow_code', 'TEST')->delete();
        DB::table('office')->whereIn('id', [$this->myOfficeId, $this->otherOfficeId])->delete();

        parent::tearDown();
    }

    private function createQrCode(): string
    {
        $code = 'QR-TST-' . strtoupper(Str::random(10));
        DB::table('dts_qr_code')->insert([
            'code_id' => $code,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);
        $this->qrCodes[] = $code;
        return $code;
    }

    public function test_dashboard_shows_only_transactions_associated_with_users_office()
    {
        // 1. Transaction where current_office is MY_OFFICE (active)
        $t1 = 'TRANS-' . strtoupper(Str::random(10));
        $qr1 = $this->createQrCode();
        DB::table('dts_transactions')->insert([
            'transaction_id' => $t1,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qr1,
        ]);
        DB::table('dts_transaction_details')->insert([
            'id' => $t1,
            'type' => 'internal',
            'created_by' => $this->testUserId,
            'originated_from' => $this->otherOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'subject' => 'TST-SUB-1',
            'classification' => 'Simple',
            'action_needed' => 'For Action',
            'transaction_flow' => 'TEST',
            'is_active' => true,
            'date_created' => now(),
            'control_number' => 'CTRL-1',
        ]);

        // 2. Transaction where originated_from is MY_OFFICE (active)
        $t2 = 'TRANS-' . strtoupper(Str::random(10));
        $qr2 = $this->createQrCode();
        DB::table('dts_transactions')->insert([
            'transaction_id' => $t2,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $this->otherOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qr2,
        ]);
        DB::table('dts_transaction_details')->insert([
            'id' => $t2,
            'type' => 'internal',
            'created_by' => $this->testUserId,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->otherOfficeCode,
            'status' => 'ongoing',
            'subject' => 'TST-SUB-2',
            'classification' => 'Simple',
            'action_needed' => 'For Action',
            'transaction_flow' => 'TEST',
            'is_active' => true,
            'date_created' => now(),
            'control_number' => 'CTRL-2',
        ]);

        // 3. Transaction that has passed through MY_OFFICE (active)
        $t3 = 'TRANS-' . strtoupper(Str::random(10));
        $qr3 = $this->createQrCode();
        DB::table('dts_transactions')->insert([
            'transaction_id' => $t3,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $this->otherOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qr3,
        ]);
        DB::table('dts_transaction_details')->insert([
            'id' => $t3,
            'type' => 'internal',
            'created_by' => $this->testUserId,
            'originated_from' => $this->otherOfficeCode,
            'current_office_hold' => $this->otherOfficeCode,
            'status' => 'ongoing',
            'subject' => 'TST-SUB-3',
            'classification' => 'Simple',
            'action_needed' => 'For Action',
            'transaction_flow' => 'TEST',
            'is_active' => true,
            'date_created' => now(),
            'control_number' => 'CTRL-3',
        ]);
        DB::table('sub_document_tracking_system_logs')->insert([
            'transaction_id' => $t3,
            'office_code' => $this->myOfficeCode,
            'type' => 'forwarded',
            'date_in' => now(),
            'date_out' => now(),
            'notes' => 'Passed through',
            'performed_by' => $this->testUserId,
        ]);

        // 4. Transaction with no relation to MY_OFFICE (active)
        $t4 = 'TRANS-' . strtoupper(Str::random(10));
        $qr4 = $this->createQrCode();
        DB::table('dts_transactions')->insert([
            'transaction_id' => $t4,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $this->otherOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qr4,
        ]);
        DB::table('dts_transaction_details')->insert([
            'id' => $t4,
            'type' => 'internal',
            'created_by' => $this->testUserId,
            'originated_from' => $this->otherOfficeCode,
            'current_office_hold' => $this->otherOfficeCode,
            'status' => 'ongoing',
            'subject' => 'TST-SUB-4',
            'classification' => 'Simple',
            'action_needed' => 'For Action',
            'transaction_flow' => 'TEST',
            'is_active' => true,
            'date_created' => now(),
            'control_number' => 'CTRL-4',
        ]);

        // 5. Inactive transaction related to MY_OFFICE (is_active = 0)
        $t5 = 'TRANS-' . strtoupper(Str::random(10));
        $qr5 = $this->createQrCode();
        DB::table('dts_transactions')->insert([
            'transaction_id' => $t5,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $this->myOfficeCode,
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qr5,
        ]);
        DB::table('dts_transaction_details')->insert([
            'id' => $t5,
            'type' => 'internal',
            'created_by' => $this->testUserId,
            'originated_from' => $this->myOfficeCode,
            'current_office_hold' => $this->myOfficeCode,
            'status' => 'ongoing',
            'subject' => 'TST-SUB-5',
            'classification' => 'Simple',
            'action_needed' => 'For Action',
            'transaction_flow' => 'TEST',
            'is_active' => false,
            'date_created' => now(),
            'control_number' => 'CTRL-5',
        ]);

        // Test component rendering and transactions count/list
        $component = Volt::test('pages.dts.index');
        
        $transactions = $component->get('transactions');
        $subjects = collect($transactions->items())->pluck('subject')->toArray();

        // Must show TST-SUB-1, TST-SUB-2, TST-SUB-3
        $this->assertContains('TST-SUB-1', $subjects);
        $this->assertContains('TST-SUB-2', $subjects);
        $this->assertContains('TST-SUB-3', $subjects);

        // Must NOT show TST-SUB-4 (no office relation) or TST-SUB-5 (inactive)
        $this->assertNotContains('TST-SUB-4', $subjects);
        $this->assertNotContains('TST-SUB-5', $subjects);
    }
}
