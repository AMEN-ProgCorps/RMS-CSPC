<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminDefaultLayoutIntegrityTest extends TestCase
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

        if ($admin) {
            $this->actingAs($admin);
        }
    }

    public function test_users_page_renders_visibly_in_both_layouts(): void
    {
        $this->withoutExceptionHandling();
        $response = $this->withSession([
            'admin_users_layout_mode' => 'default',
            'admin_management_layout_mode' => 'default',
        ])->get(route('admin.accounts.users'));
        $response->assertStatus(200);

        // 1. Default Mode
        $comp = Volt::test('pages.admin.accounts.users')
            ->call('setLayoutMode', 'default')
            ->assertSet('layoutMode', 'default')
            ->assertHasNoErrors()
            ->assertSee('User Accounts & Credentials', false)
            ->assertSee('Users Directory')
            ->assertSee('admin-users-container');

        // Test clicking New User in Default mode
        $comp->call('startCreate')
            ->assertSet('selectedUserId', -1)
            ->assertHasNoErrors()
            ->assertSee('Configure New User')
            ->assertSee('user-details-panel--1');

        // Test selecting an existing user
        $testUser = User::first();
        if ($testUser) {
            $comp->call('selectUser', $testUser->id)
                ->assertSet('selectedUserId', $testUser->id)
                ->assertHasNoErrors()
                ->assertSee('user-details-panel-' . $testUser->id);
        }

        // Verify requestors tab in Default mode
        $comp->call('switchTab', 'requestors')
            ->assertSet('activeTab', 'requestors')
            ->assertHasNoErrors()
            ->assertSee('Requestor Contacts Directory')
            ->assertSee('admin-users-container');

        // 2. Horizontal Mode
        $comp->call('setLayoutMode', 'horizontal')
            ->assertSet('layoutMode', 'horizontal')
            ->assertHasNoErrors()
            ->assertSee('miller-container');
    }

    public function test_offices_page_renders_visibly_in_both_layouts(): void
    {
        $this->withoutExceptionHandling();
        $response = $this->withSession([
            'admin_offices_layout_mode' => 'default',
            'admin_management_layout_mode' => 'default',
        ])->get(route('admin.accounts.offices'));
        $response->assertStatus(200);

        // 1. Default Mode
        $comp = Volt::test('pages.admin.accounts.offices')
            ->call('setLayoutMode', 'default')
            ->assertSet('layoutMode', 'default')
            ->assertHasNoErrors()
            ->assertSee('Offices Directory')
            ->assertSee('admin-offices-container');

        // Verify other_offices tab in Default mode
        $comp->call('switchTab', 'other_offices')
            ->assertSet('activeTab', 'other_offices')
            ->assertHasNoErrors()
            ->assertSee('Registered External Entities')
            ->assertSee('admin-offices-container');

        // 2. Horizontal Mode
        $comp->call('setLayoutMode', 'horizontal')
            ->assertSet('layoutMode', 'horizontal')
            ->assertHasNoErrors()
            ->assertSee('miller-container');
    }

    public function test_roles_page_renders_visibly_in_both_layouts(): void
    {
        $this->withoutExceptionHandling();
        // Full HTTP route test with default layout in session
        $response = $this->withSession([
            'admin_roles_layout_mode' => 'default',
            'admin_management_layout_mode' => 'default',
        ])->get(route('admin.accounts.roles'));
        $response->assertStatus(200);

        // 1. Default Mode (initial without selection)
        $comp = Volt::test('pages.admin.accounts.roles')
            ->call('setLayoutMode', 'default')
            ->assertSet('layoutMode', 'default')
            ->assertHasNoErrors()
            ->assertSee('Roles Directory')
            ->assertSee('admin-roles-container');

        // Select create mode to render the permissions details panel
        $comp->call('startCreate')
            ->assertHasNoErrors()
            ->assertSee('Modify Issuance Control Number')
            ->assertSee('Modify Control Number');

        // 2. Horizontal Mode
        $comp->call('setLayoutMode', 'horizontal')
            ->assertSet('layoutMode', 'horizontal')
            ->assertHasNoErrors()
            ->assertSee('miller-container');
    }

    public function test_transaction_flows_page_renders_visibly_in_both_layouts(): void
    {
        $this->withoutExceptionHandling();
        $response = $this->withSession([
            'admin_flows_layout_mode' => 'default',
            'admin_management_layout_mode' => 'default',
        ])->get(route('admin.dts.transaction-flows'));
        $response->assertStatus(200);

        // 1. Default Mode
        $comp = Volt::test('pages.admin.dts.transaction-flows')
            ->call('setLayoutMode', 'default')
            ->assertSet('layoutMode', 'default')
            ->assertHasNoErrors()
            ->assertSee('Predefined Flow Manager')
            ->assertSee('activity-logs-container');

        // Verify custom tab in Default mode
        $comp->set('activeTab', 'custom')
            ->assertHasNoErrors()
            ->assertSee('Custom Flows Directory');

        // 2. Horizontal Mode
        $comp->call('setLayoutMode', 'horizontal')
            ->assertSet('layoutMode', 'horizontal')
            ->assertHasNoErrors()
            ->assertSee('miller-container');
    }

    public function test_tickets_page_renders_visibly_in_both_layouts(): void
    {
        $this->withoutExceptionHandling();
        $response = $this->withSession([
            'admin_tickets_layout_mode' => 'default',
            'admin_management_layout_mode' => 'default',
        ])->get(route('admin.tickets'));
        $response->assertStatus(200);

        // 1. Default Mode
        $comp = Volt::test('pages.admin.tickets.index')
            ->call('setLayoutMode', 'default')
            ->assertSet('layoutMode', 'default')
            ->assertHasNoErrors()
            ->assertSee('System Error Tickets')
            ->assertSee('activity-logs-container');

        // 2. Horizontal Mode
        $comp->call('setLayoutMode', 'horizontal')
            ->assertSet('layoutMode', 'horizontal')
            ->assertHasNoErrors()
            ->assertSee('miller-container');
    }
}
