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

    public function test_transaction_flow_drag_scope_reassignment()
    {
        $flow = DB::table('dts_transaction_flow')->first();
        if ($flow) {
            Volt::test('pages.admin.dts.transaction-flows')
                ->call('changeFlowPurpose', $flow->id, 'issuances')
                ->assertHasNoErrors()
                ->assertSee('moved to Issuances scope!');

            $this->assertDatabaseHas('dts_transaction_flow', [
                'id' => $flow->id,
                'flow_use' => 'issuances',
            ]);
        }
    }
}
