<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class DtsListOfTransactionOfficeFilterTest extends TestCase
{
    use DatabaseTransactions;

    private string $accTable;
    private string $accDetailsTable;
    private string $condKeyTable;
    private string $condDetailsTable;
    private string $officeTable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->accDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->condKeyTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $this->condDetailsTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
        $this->officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
    }

    private function createTestUserWithPermissions(array $permissions, string $officeCode): User
    {
        $maxKeyId = (DB::table($this->condKeyTable)->max('id') ?: 0) + 1;
        $maxDetailId = (DB::table($this->condDetailsTable)->max('key_id') ?: 0) + 1;
        $roleId = max($maxKeyId, $maxDetailId);

        DB::table($this->condDetailsTable)->insert(array_merge([
            'key_id'                  => $roleId,
            'is_sadm'                 => false,
            'is_admin'                => false,
            'can_access_dts'          => true,
            'can_dts_use_internal'    => true,
            'can_dts_use_external'    => true,
            'can_dts_use_application' => true,
            'can_dts_use_issuance'    => true,
            'can_dts_view_all_list'   => false,
        ], $permissions));

        DB::table($this->condKeyTable)->insert([
            'id'           => $roleId,
            'key_name'     => 'Test Filter Role ' . uniqid(),
            'modifier_key' => $roleId,
        ]);

        $userId = DB::table($this->accTable)->insertGetId([
            'username'       => 'test_filter_' . uniqid(),
            'password'       => bcrypt('password'),
            'account_status' => 1,
            'account_role'   => $roleId,
            'account_active' => true,
        ]);

        $officeId = DB::table($this->officeTable)->where('office_code', $officeCode)->value('id');
        if (!$officeId) {
            $officeId = DB::table($this->officeTable)->insertGetId([
                'office_name' => 'Office ' . $officeCode,
                'office_code' => $officeCode,
                'is_active'   => true,
            ]);
        }

        DB::table($this->accDetailsTable)->insert([
            'account_id'  => $userId,
            'office_id'   => $officeId,
            'first_name'  => 'Test',
            'last_name'   => 'FilterUser',
            'email'       => 'filter_' . uniqid() . '@example.com',
        ]);

        return User::find($userId);
    }

    public function test_user_without_clearance_does_not_see_office_filter(): void
    {
        $user = $this->createTestUserWithPermissions([
            'can_dts_view_all_list' => false,
            'is_sadm'               => false,
        ], 'OFFICE_A');

        Auth::login($user);

        Volt::test('pages.dts.list.internal')
            ->assertDontSeeHtml('title="Filter by Office"')
            ->assertSet('selectedOffice', 'all');

        Volt::test('pages.dts.list.external')
            ->assertDontSeeHtml('title="Filter by Office"')
            ->assertSet('selectedOffice', 'all');

        Volt::test('pages.dts.list.application-letters')
            ->assertDontSeeHtml('title="Filter by Office"')
            ->assertSet('selectedOffice', 'all');

        Volt::test('pages.dts.list.issuances')
            ->assertDontSeeHtml('title="Filter by Office"')
            ->assertSet('selectedOffice', 'all');
    }

    public function test_user_with_clearance_sees_office_filter_and_can_filter(): void
    {
        $user = $this->createTestUserWithPermissions([
            'can_dts_view_all_list' => true,
            'is_sadm'               => false,
        ], 'OFFICE_A');

        Auth::login($user);

        Volt::test('pages.dts.list.internal')
            ->assertSeeHtml('title="Filter by Office"')
            ->assertSeeHtml('All Offices')
            ->set('selectedOffice', 'OFFICE_A')
            ->assertSet('selectedOffice', 'OFFICE_A')
            ->call('resetFilters')
            ->assertSet('selectedOffice', 'all');

        Volt::test('pages.dts.list.external')
            ->assertSeeHtml('title="Filter by Office"')
            ->assertSeeHtml('All Offices')
            ->set('selectedOffice', 'OFFICE_A')
            ->assertSet('selectedOffice', 'OFFICE_A')
            ->call('resetFilters')
            ->assertSet('selectedOffice', 'all');

        Volt::test('pages.dts.list.application-letters')
            ->assertSeeHtml('title="Filter by Office"')
            ->assertSeeHtml('All Offices')
            ->set('selectedOffice', 'OFFICE_A')
            ->assertSet('selectedOffice', 'OFFICE_A')
            ->call('resetFilters')
            ->assertSet('selectedOffice', 'all');

        Volt::test('pages.dts.list.issuances')
            ->assertSeeHtml('title="Filter by Office"')
            ->assertSeeHtml('All Offices')
            ->set('selectedOffice', 'OFFICE_A')
            ->assertSet('selectedOffice', 'OFFICE_A')
            ->call('resetFilters')
            ->assertSet('selectedOffice', 'all');
    }

    public function test_super_admin_has_office_filter(): void
    {
        $user = $this->createTestUserWithPermissions([
            'can_dts_view_all_list' => false,
            'is_sadm'               => true,
        ], 'OFFICE_A');

        Auth::login($user);

        Volt::test('pages.dts.list.internal')
            ->assertSeeHtml('title="Filter by Office"')
            ->assertSeeHtml('All Offices');
    }
}
