<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class RdpForApprovalClearanceTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $roleId;
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

        $this->roleId = (int) (DB::table($roleTable)->max('id') ?: 800) + 1;

        DB::table($this->roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => false,
            'can_access_dts' => true,
            'can_access_rdp' => true,
            'can_access_dcs' => true,
            'can_rdp_access_for_approval' => false,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Test RDP Approval Role ' . $this->roleId,
            'key_description' => 'Role for testing for-approval clearance',
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $officeRow = DB::table($this->tOffice)->first();
        $officeCode = $officeRow ? $officeRow->office_code : 'ICTU';
        $officeId = $officeRow ? $officeRow->id : 1;

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'test_approval_user_' . $this->roleId,
            'password' => bcrypt('secret123'),
            'account_role' => $this->roleId,
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Approval',
            'last_name' => 'Tester',
            'email' => 'approval_' . $this->userId . '@test.com',
            'office_id' => $officeId,
        ]);
    }

    public function test_user_without_clearance_is_redirected_away_from_for_approval()
    {
        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.pending.for-approval'));
        $response->assertRedirect(route('rdp.pending.list'));
    }

    public function test_user_with_clearance_can_access_for_approval()
    {
        // Grant clearance
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_for_approval' => true,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.pending.for-approval'));
        $response->assertOk();
    }

    public function test_admin_roles_component_loads_and_saves_approval_clearance()
    {
        // Act as super admin
        $sadmId = (int) (DB::table($this->tAccount)->max('id') ?: 900) + 1;
        $sadmRole = (int) (DB::table('sys_condition_key')->max('id') ?: 900) + 1;

        DB::table($this->roleDetails)->insert([
            'key_id' => $sadmRole,
            'is_sadm' => true,
            'can_access_rdp' => true,
            'can_rdp_access_for_approval' => true,
        ]);
        DB::table('sys_condition_key')->insert([
            'id' => $sadmRole,
            'key_name' => 'SADM ' . $sadmRole,
            'modifier_key' => $sadmRole,
            'is_active' => true,
        ]);
        $sadmId = DB::table($this->tAccount)->insertGetId([
            'username' => 'sadm_' . $sadmRole,
            'password' => bcrypt('secret123'),
            'account_role' => $sadmRole,
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);
        DB::table($this->tAccountDetails)->insert([
            'account_id' => $sadmId,
            'first_name' => 'Super',
            'last_name' => 'Admin',
            'email' => 'sadm_' . $sadmRole . '@test.com',
        ]);

        $sadmUser = User::find($sadmId);

        Livewire::actingAs($sadmUser)
            ->test('pages.admin.accounts.roles')
            ->call('selectRole', $this->roleId)
            ->assertSet('canRdpAccessForApproval', false)
            ->set('canRdpAccessForApproval', true)
            ->call('saveRoleChanges')
            ->assertHasNoErrors();

        $savedClearance = (bool) DB::table($this->roleDetails)
            ->where('key_id', $this->roleId)
            ->value('can_rdp_access_for_approval');

        $this->assertTrue($savedClearance, 'The can_rdp_access_for_approval clearance must be saved to true in DB');
    }
}
