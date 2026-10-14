<?php

namespace Tests\Feature;

use App\Helpers\DtsFlowHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CustomFlowScopeSettingTest extends TestCase
{
    use DatabaseTransactions;

    private User $adminUser;
    private User $regularUser;
    private string $sysTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sysTable = \Illuminate\Support\Facades\Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';

        // Superadmin user for settings test
        $this->adminUser = User::firstOrCreate(
            ['username' => 'test_admin_scope'],
            [
                'password' => bcrypt('password'),
                'account_role' => 1,
                'is_active' => true,
            ]
        );

        $condDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
        DB::table($condDetailsTable)->updateOrInsert(
            ['key_id' => $this->adminUser->account_role],
            [
                'is_sadm' => true,
                'can_access_settings' => true,
                'can_access_dts' => true,
                'can_dts_use_internal' => true,
                'can_dts_use_external' => true,
                'can_dts_use_application' => true,
                'can_dts_use_issuance' => true,
                'can_dts_create_own_flow' => true,
            ]
        );

        // Ensure user details exist
        $detailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $office = DB::table($officeTable)->first();
        $officeId = $office ? $office->id : 1;

        DB::table($detailsTable)->updateOrInsert(
            ['account_id' => $this->adminUser->id],
            [
                'office_id' => $officeId,
                'first_name' => 'Admin Scope',
                'last_name' => 'Tester',
                'email' => 'admin_scope@example.com',
            ]
        );
    }

    public function test_dts_flow_helper_scope_selection_allowed(): void
    {
        // Default / 'false'
        DB::table($this->sysTable)->updateOrInsert(
            ['key' => 'dts_allow_custom_flow_scope_selection'],
            ['value' => 'false', 'updated_at' => now()]
        );
        $this->assertFalse(DtsFlowHelper::isScopeSelectionAllowed());

        // Enabled / 'true'
        DB::table($this->sysTable)->updateOrInsert(
            ['key' => 'dts_allow_custom_flow_scope_selection'],
            ['value' => 'true', 'updated_at' => now()]
        );
        $this->assertTrue(DtsFlowHelper::isScopeSelectionAllowed());
    }

    public function test_admin_settings_can_toggle_and_save_custom_flow_scope_setting(): void
    {
        $this->actingAs($this->adminUser);

        // Set to false initially
        DB::table($this->sysTable)->updateOrInsert(
            ['key' => 'dts_allow_custom_flow_scope_selection'],
            ['value' => 'false', 'updated_at' => now()]
        );

        Volt::test('pages.admin.settings.index')
            ->set('settingsTab', 'dts')
            ->assertSet('dtsAllowCustomFlowScopeSelection', false)
            ->set('dtsAllowCustomFlowScopeSelection', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $saved = DB::table($this->sysTable)->where('key', 'dts_allow_custom_flow_scope_selection')->value('value');
        $this->assertEquals('true', $saved);
    }

    public function test_when_scope_selection_is_disabled_flow_forces_office_scope_and_input_is_hidden(): void
    {
        $this->actingAs($this->adminUser);

        DB::table($this->sysTable)->updateOrInsert(
            ['key' => 'dts_allow_custom_flow_scope_selection'],
            ['value' => 'false', 'updated_at' => now()]
        );

        $testComponent = Volt::test('pages.dts.create.internal')
            ->assertSet('allowCustomFlowScopeSelection', false)
            ->call('openCustomFlowCreator')
            ->assertSet('showCustomFlowModal', true)
            ->assertSet('customFlowFor', 'office')
            ->assertDontSee('Who can use this flow?');

        // Attempt to create custom flow
        $testComponent
            ->set('customFlowDocType', 'Test Internal Office Flow')
            ->set('customFlowSelectedOffice', 'ORIGIN')
            ->call('addToCustomFlowSequence')
            ->set('customFlowFor', 'user') // Try to tamper
            ->call('saveCustomFlow')
            ->assertHasNoErrors();

        // Verify it was saved as 'office'
        $savedFlow = DB::table('dts_transaction_flow')
            ->where('flow_name', 'Test Internal Office Flow')
            ->first();

        $this->assertNotNull($savedFlow);
        $this->assertEquals('office', $savedFlow->flow_for);
    }

    public function test_when_scope_selection_is_enabled_flow_allows_personal_user_scope_and_input_is_visible(): void
    {
        $this->actingAs($this->adminUser);

        DB::table($this->sysTable)->updateOrInsert(
            ['key' => 'dts_allow_custom_flow_scope_selection'],
            ['value' => 'true', 'updated_at' => now()]
        );

        $testComponent = Volt::test('pages.dts.create.internal')
            ->assertSet('allowCustomFlowScopeSelection', true)
            ->call('openCustomFlowCreator')
            ->assertSet('showCustomFlowModal', true)
            ->assertSet('customFlowFor', 'user')
            ->assertSee('Who can use this flow?')
            ->set('customFlowDocType', 'Test Internal Personal Flow')
            ->set('customFlowSelectedOffice', 'ORIGIN')
            ->call('addToCustomFlowSequence')
            ->set('customFlowFor', 'user')
            ->call('saveCustomFlow')
            ->assertHasNoErrors();

        $savedFlow = DB::table('dts_transaction_flow')
            ->where('flow_name', 'Test Internal Personal Flow')
            ->first();

        $this->assertNotNull($savedFlow);
        $this->assertEquals('user', $savedFlow->flow_for);
    }
}
