<?php

namespace Tests\Feature;

use App\Models\role_permission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class IssuanceClearanceRolesTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $roleId;
    private int $officeId;
    private string $officeCode = 'TEST_ORIGIN_CLR';

    private string $tOffice;
    private string $tAccount;
    private string $tAccountDetails;
    private string $roleDetails;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccount = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        $roleTable = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $this->roleDetails = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';

        $base = (int) (DB::table($roleTable)->max('id') ?: 300) + 1;
        $this->roleId = $base;

        DB::table($this->roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => false,
            'can_access_dts' => true,
            'can_access_rdp' => true,
            'can_access_dcs' => true,
            'can_dts_use_issuance' => true,
            'can_dts_issuance_receiving_office' => false,
            'can_dts_issuance_transaction_flow' => false,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Clearance Test Role ' . $base,
            'key_description' => 'Test role for clearance flags',
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $this->officeId = DB::table($this->tOffice)->insertGetId([
            'office_name' => 'Origin Unit ' . $base,
            'office_code' => $this->officeCode,
            'is_active' => true,
        ]);

        DB::table($this->tOffice)->updateOrInsert(
            ['office_code' => 'TARGET_CLR_A'],
            ['office_name' => 'Clearance Target Office A', 'is_active' => true]
        );

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'clr_user_' . $base,
            'password' => bcrypt('password'),
            'account_status' => 1,
            'account_role' => $this->roleId,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Clearance',
            'last_name' => 'Tester',
            'office_id' => $this->officeId,
            'email' => 'clr_' . $base . '@example.com',
        ]);

        $user = User::find($this->userId);
        Auth::login($user);
    }

    public function test_model_casts_and_fillables()
    {
        $perm = new role_permission();
        $this->assertContains('can_dts_issuance_receiving_office', $perm->getFillable());
        $this->assertContains('can_dts_issuance_transaction_flow', $perm->getFillable());
        $this->assertEquals('boolean', $perm->getCasts()['can_dts_issuance_receiving_office'] ?? null);
        $this->assertEquals('boolean', $perm->getCasts()['can_dts_issuance_transaction_flow'] ?? null);
    }

    public function test_user_with_only_receiving_office_clearance_does_not_require_transaction_flow()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_dts_issuance_receiving_office' => true,
            'can_dts_issuance_transaction_flow' => false,
        ]);

        $component = Livewire::test('pages.dts.create.issuances');
        $this->assertTrue($component->get('canReceivingOffice'));
        $this->assertFalse($component->get('canTransactionFlow'));

        // Saving without transaction_flow but with a singular office should succeed with linear flow
        $subject = 'Direct Linear Memo ' . uniqid();
        $component->set('issuance_type', 'NM')
            ->set('subject', $subject)
            ->call('selectFreeFlowOffice', 'TARGET_CLR_A')
            ->call('save')
            ->assertHasNoErrors();

        $saved = DB::table('dts_transaction_details')->where('subject', $subject)->first();
        $this->assertNotNull($saved);
        $this->assertEquals('TARGET_CLR_A', $saved->current_office_hold);
    }

    public function test_user_with_only_receiving_office_clearance_broadcasts_on_all()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_dts_issuance_receiving_office' => true,
            'can_dts_issuance_transaction_flow' => false,
        ]);

        $component = Livewire::test('pages.dts.create.issuances');
        $this->assertTrue($component->get('canReceivingOffice'));
        $this->assertFalse($component->get('canTransactionFlow'));

        $subject = 'Broadcast All Memo ' . uniqid();
        $component->set('issuance_type', 'NM')
            ->set('subject', $subject)
            ->call('selectAllReceivingOffices');

        $this->assertTrue($component->get('hasHub'));

        $component->call('save')
            ->assertHasNoErrors();

        $saved = DB::table('dts_transaction_details')->where('subject', $subject)->first();
        $this->assertNotNull($saved);
    }
}
