<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminMillerConsoleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        $admin = null;
        foreach (User::all() as $u) {
            $p = $u->permissions;
            if ($p && ($p->is_sadm || $p->is_admin)) {
                $admin = $u;
                break;
            }
        }

        if (!$admin) {
            $admin = User::first();
        }

        Auth::login($admin);
    }

    public function test_users_page_renders_cleanly()
    {
        $comp = Volt::test('pages.admin.accounts.users');
        $comp->assertHasNoErrors();
        $comp->assertSee('Staff Accounts');
    }

    public function test_users_page_requestor_mode()
    {
        $comp = Volt::test('pages.admin.accounts.users')
            ->set('activeTab', 'requestors');
        $comp->assertHasNoErrors();
        $comp->assertSee('Requestor Filters');
        $comp->assertSee('All Requestors');
    }

    public function test_offices_page_renders_cleanly()
    {
        $comp = Volt::test('pages.admin.accounts.offices');
        $comp->assertHasNoErrors();
        $comp->assertSee('Clusters');
    }

    public function test_roles_page_renders_cleanly()
    {
        $comp = Volt::test('pages.admin.accounts.roles');
        $comp->assertHasNoErrors();
        $comp->assertSee('Roles Directory');
    }

    public function test_brackets_page_renders_cleanly()
    {
        $comp = Volt::test('pages.admin.rdp.brackets');
        $comp->assertHasNoErrors();
    }

    public function test_transaction_flows_predefined_mode()
    {
        $comp = Volt::test('pages.admin.dts.transaction-flows');
        $comp->assertHasNoErrors();
        $comp->assertSee('Predefined Flows');
        $comp->assertSee('Scopes & Purpose', false);
        $comp->assertSee('Routing Pipeline & Distribution', false);
    }

    public function test_transaction_flows_custom_mode()
    {
        $comp = Volt::test('pages.admin.dts.transaction-flows')
            ->set('activeTab', 'custom');
        $comp->assertHasNoErrors();
        $comp->assertSee('Custom Flows Directory');
        $comp->assertSee('Custom Pipeline Stops');
    }

    public function test_transaction_flow_drag_reorder_ladder()
    {
        // Test dragging step 2 to step 1
        $comp = Volt::test('pages.admin.dts.transaction-flows')
            ->set('flowOffices', ['ORIGIN', 'TEST-A', 'TEST-B', 'ORIGIN'])
            ->call('reorderStep', 2, 1)
            ->assertSet('flowOffices', ['ORIGIN', 'TEST-B', 'TEST-A', 'ORIGIN'])
            ->assertHasNoErrors();
    }

    public function test_user_drag_reassign_office()
    {
        $user = User::first();
        $office = DB::table('sys_office')->where('id', '!=', $user->details?->office_id ?? 0)->first();

        if ($user && $office) {
            Volt::test('pages.admin.accounts.users')
                ->call('assignUserOffice', $user->id, $office->id)
                ->assertHasNoErrors()
                ->assertSee('successfully reassigned to');

            $this->assertDatabaseHas('sys_account_details', [
                'account_id' => $user->id,
                'office_id' => $office->id,
            ]);
        }
    }

    public function test_office_drag_reassign_cluster()
    {
        $office = DB::table('sys_office')->first();
        $cluster = DB::table('sys_cluster')->first();

        if ($office && $cluster) {
            Volt::test('pages.admin.accounts.users')
                ->call('assignOfficeCluster', $office->id, $cluster->cluster_code)
                ->assertHasNoErrors()
                ->assertSee('assigned to cluster');

            $this->assertDatabaseHas('sys_office', [
                'id' => $office->id,
                'cluster' => $cluster->cluster_code,
            ]);
        }
    }
}
