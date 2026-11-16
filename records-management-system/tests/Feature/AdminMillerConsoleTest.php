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
            if ($p && $p->is_sadm) {
                $admin = $u;
                break;
            }
        }

        if (!$admin) {
            foreach (User::all() as $u) {
                $p = $u->permissions;
                if ($p && ($p->is_sadm || $p->is_admin)) {
                    $admin = $u;
                    break;
                }
            }
        }

        if (!$admin) {
            $admin = User::first();
        }

        if ($admin && $admin->permissions) {
            $admin->permissions->is_sadm = true;
            $admin->permissions->can_sadm_modify_account = true;
            $admin->permissions->save();
        }

        $subsystemTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $pk = \Illuminate\Support\Facades\Schema::hasColumn($subsystemTbl, 'subsystem_id') ? 'subsystem_id' : 'id';
        if (!DB::table($subsystemTbl)->where($pk, 3)->exists()) {
            $insertData = [
                $pk => 3,
                'subsystem_name' => 'Admin Console',
                'is_active' => true,
                'created_at' => now(),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn($subsystemTbl, 'subsystem_version')) {
                $insertData['subsystem_version'] = '1.0.0';
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn($subsystemTbl, 'update_at')) {
                $insertData['update_at'] = now();
            } elseif (\Illuminate\Support\Facades\Schema::hasColumn($subsystemTbl, 'updated_at')) {
                $insertData['updated_at'] = now();
            }
            DB::table($subsystemTbl)->insert($insertData);
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
        $officeTbl = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $office = DB::table($officeTbl)->first();
        $cluster = \App\Models\Cluster::first();

        if ($office && $cluster) {
            Volt::test('pages.admin.accounts.users')
                ->call('assignOfficeCluster', $office->id, $cluster->cluster_code)
                ->assertHasNoErrors()
                ->assertSee('assigned to cluster');

            $this->assertDatabaseHas($officeTbl, [
                'id' => $office->id,
                'cluster' => $cluster->cluster_code,
            ]);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_users_layout_mode_switch()
    {
        $comp = Volt::test('pages.admin.accounts.users');
        $comp->assertSet('layoutMode', 'horizontal');
        $comp->call('setLayoutMode', 'default');
        $comp->assertSet('layoutMode', 'default');
        $comp->assertHasNoErrors();
    }

    public function test_roles_layout_mode_switch()
    {
        $comp = Volt::test('pages.admin.accounts.roles');
        $comp->assertSet('layoutMode', 'horizontal');
        $comp->call('setLayoutMode', 'default');
        $comp->assertSet('layoutMode', 'default');
        $comp->assertHasNoErrors();
    }

    public function test_offices_layout_mode_switch()
    {
        $comp = Volt::test('pages.admin.accounts.offices');
        $comp->assertSet('layoutMode', 'horizontal');
        $comp->call('setLayoutMode', 'default');
        $comp->assertSet('layoutMode', 'default');
        $comp->assertHasNoErrors();
    }

    public function test_transaction_flows_layout_mode_switch()
    {
        $comp = Volt::test('pages.admin.dts.transaction-flows');
        $comp->assertSet('layoutMode', 'horizontal');
        $comp->call('setLayoutMode', 'default');
        $comp->assertSet('layoutMode', 'default');
        $comp->assertHasNoErrors();
    }
}
