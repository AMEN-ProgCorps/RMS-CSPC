<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class RdpSectionClearancesTest extends TestCase
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

        $this->roleId = (int) (DB::table($roleTable)->max('id') ?: 850) + 1;

        DB::table($this->roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => false,
            'can_access_dts' => true,
            'can_access_rdp' => true,
            'can_access_dcs' => true,
            'can_rdp_access_received_docs' => true,
            'can_rdp_access_add_records' => true,
            'can_rdp_access_drafts' => true,
            'can_rdp_access_pending' => true,
            'can_rdp_access_references' => true,
            'can_rdp_access_reports' => true,
            'can_rdp_access_manage_files' => true,
            'can_rdp_generate_reports' => true,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Test RDP Clearance Role ' . $this->roleId,
            'key_description' => 'Role for testing RDP section clearances',
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $officeRow = DB::table($this->tOffice)->first();
        $officeId = $officeRow ? $officeRow->id : 1;

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'test_rdp_user_' . $this->roleId,
            'password' => bcrypt('secret123'),
            'account_role' => $this->roleId,
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Clearance',
            'last_name' => 'Tester',
            'email' => 'clearance_' . $this->userId . '@test.com',
            'office_id' => $officeId,
        ]);
    }

    public function test_user_without_access_reports_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_reports' => false,
        ]);

        $user = User::find($this->userId);

        $response1 = $this->actingAs($user)->get(route('rdp.reports.nap-form-1'));
        $response1->assertRedirect(route('rdp'));

        $response2 = $this->actingAs($user)->get(route('rdp.reports.nap-form-2'));
        $response2->assertRedirect(route('rdp'));

        $response3 = $this->actingAs($user)->get(route('rdp.reports.nap-form-3'));
        $response3->assertRedirect(route('rdp'));

        $response4 = $this->actingAs($user)->get(route('rdp.reports.master-lifecycle'));
        $response4->assertRedirect(route('rdp'));
    }

    public function test_user_with_access_reports_can_view_but_cannot_generate_when_clearance_false()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_reports' => true,
            'can_rdp_generate_reports' => false,
        ]);

        $user = User::find($this->userId);

        // Can view the reports pages
        $response1 = $this->actingAs($user)->get(route('rdp.reports.nap-form-1'));
        $response1->assertOk();

        $response2 = $this->actingAs($user)->get(route('rdp.reports.nap-form-2'));
        $response2->assertOk();

        $response3 = $this->actingAs($user)->get(route('rdp.reports.nap-form-3'));
        $response3->assertOk();

        // Testing Livewire form generation actions
        $this->actingAs($user);

        Livewire::test('pages.rdp.reports.nap-form-1')
            ->call('openClusterModal')
            ->assertSet('errorMessage', 'You do not have clearance to create or generate NAP Form 1.');

        Livewire::test('pages.rdp.reports.nap-form-1')
            ->call('openPrintModal')
            ->assertSet('errorMessage', 'You do not have clearance to generate or print NAP Form 1.');

        Livewire::test('pages.rdp.reports.nap-form-2')
            ->call('openClusterModal')
            ->assertSet('errorMessage', 'You do not have clearance to create or generate NAP Form 2.');

        Livewire::test('pages.rdp.reports.nap-form-2')
            ->call('openPrintModal')
            ->assertSet('errorMessage', 'You do not have clearance to generate or print NAP Form 2.');

        Livewire::test('pages.rdp.reports.nap-form-3')
            ->call('openClusterModal')
            ->assertSet('errorMessage', 'You do not have clearance to create or generate NAP Form 3.');

        Livewire::test('pages.rdp.reports.nap-form-3')
            ->call('openPrintModal')
            ->assertSet('errorMessage', 'You do not have clearance to generate or print NAP Form 3.');
    }

    public function test_user_without_received_docs_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_received_docs' => false,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.received-documents.dts'));
        $response->assertRedirect(route('rdp'));
    }

    public function test_user_without_add_records_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_add_records' => false,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.add-records.inventory-and-appraisal'));
        $response->assertRedirect(route('rdp'));
    }

    public function test_user_without_drafts_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_drafts' => false,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.draft.inventory-and-appraisal'));
        $response->assertRedirect(route('rdp'));
    }

    public function test_user_without_pending_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_pending' => false,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.pending.list'));
        $response->assertRedirect(route('rdp'));
    }

    public function test_user_without_references_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_references' => false,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.references.show', ['type' => 'ph-nap']));
        $response->assertRedirect(route('rdp'));
    }

    public function test_user_without_manage_files_clearance_is_redirected()
    {
        DB::table($this->roleDetails)->where('key_id', $this->roleId)->update([
            'can_rdp_access_manage_files' => false,
        ]);

        $user = User::find($this->userId);

        $response = $this->actingAs($user)->get(route('rdp.manage-files'));
        $response->assertRedirect(route('rdp'));
    }
}
