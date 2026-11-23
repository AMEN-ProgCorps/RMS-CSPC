<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardFilterTest extends TestCase
{
    protected function getAuthorizedUser(): User
    {
        $user = User::first() ?? User::factory()->create();
        $perms = $user->permissions;
        if ($perms) {
            $perms->update([
                'is_sadm' => true,
                'can_access_dts_admin' => true,
            ]);
        }
        return $user;
    }

    public function test_dashboard_renders_with_all_filter_options(): void
    {
        $user = $this->getAuthorizedUser();

        // Check initial default is month, user office, and current year
        $curYear = (int) date('Y');
        $initTest = Livewire::actingAs($user)->test('pages.admin.console.index');
        $initTest->assertStatus(200);
        $initTest->assertSee('header-office-filter-wrapper');
        $initTest->assertSee('header-office-select');
        $initTest->assertSee('header-year-filter-wrapper');
        $initTest->assertSee('header-year-select');
        $this->assertEquals('month', $initTest->get('timeFilter'));
        $this->assertEquals($curYear, $initTest->get('cardYear'));
        $initTest->assertViewHas('officesList');
        $initTest->assertViewHas('availableYears');
        $years = $initTest->viewData('availableYears');
        $this->assertContains(2026, $years);
        $this->assertEquals(2026, min($years));
        $this->assertEquals(2026, max($years));
        $expectedOffice = $user?->details?->office?->office_code ?? 'all';
        $this->assertEquals($expectedOffice, $initTest->get('cardOffice'));

        // Test changing year to past or future year (e.g. 2027)
        $testYear = Livewire::actingAs($user)
            ->test('pages.admin.console.index')
            ->set('cardYear', 2027);
        $testYear->assertStatus(200);
        $this->assertEquals(2027, $testYear->get('cardYear'));
        $testYear->assertSee('All Months (2027)');

        foreach (['week', 'month', 'year', 'max'] as $filter) {
            $test = Livewire::actingAs($user)
                ->test('pages.admin.console.index')
                ->call('setTimeFilter', $filter);

            $test->assertStatus(200);
            $test->assertSee('Received Transactions');
            $test->assertSee(strtoupper($filter));
            $this->assertEquals($filter, $test->get('timeFilter'));
        }
    }

    public function test_card_monthly_dropdown_filters_and_expand_elements(): void
    {
        $user = $this->getAuthorizedUser();

        $test = Livewire::actingAs($user)
            ->test('pages.admin.console.index')
            ->set('recFilter', '10')
            ->set('fwdFilter', '9')
            ->set('compFilter', '8');

        $test->assertStatus(200);
        $test->assertSee('DTS Subsystem');
        $test->assertSee('EXPND');
        $test->assertSee('card-subsystem-tab');
        $test->assertSee('card-expand-tab');
        $test->assertSee('card-dropdown-filter');
        $test->assertSee('upscale-modal-dialog');
        $this->assertEquals('10', $test->get('recFilter'));
        $this->assertEquals('9', $test->get('fwdFilter'));
        $this->assertEquals('8', $test->get('compFilter'));
    }

    public function test_interactive_modal_configurable_filters_and_records_tab(): void
    {
        $user = $this->getAuthorizedUser();

        $test = Livewire::actingAs($user)
            ->test('pages.admin.console.index')
            ->call('openModalWithCard', 'received')
            ->set('modalRange', '10')
            ->set('modalOffice', 'all')
            ->set('modalAction', 'For Approval')
            ->set('modalTab', 'records');

        $test->assertStatus(200);
        $test->assertSee('modal-filter-toolbar');
        $test->assertSee('modal-filter-select');
        $test->assertSee('modal-view-tabs');
        $test->assertSee('records-table');
        $this->assertEquals('received', $test->get('modalCard'));
        $this->assertEquals('10', $test->get('modalRange'));
        $this->assertEquals('For Approval', $test->get('modalAction'));
        $this->assertEquals('records', $test->get('modalTab'));

        // Test reset filters
        $test->call('resetModalFilters');
        $this->assertEquals('all', $test->get('modalRange'));
        $this->assertEquals('all', $test->get('modalAction'));
    }

    public function test_rdp_subsystem_cards_and_modal(): void
    {
        $user = $this->getAuthorizedUser();

        $test = Livewire::actingAs($user)
            ->test('pages.admin.console.index');

        $test->assertStatus(200);
        $test->assertSee('RDP Subsystem');
        $test->assertSee('Records Created');
        $test->assertSee('Approved Forms');
        $test->assertSee('Disposed Forms');
        $test->assertSee('NAP Form 1');
        $test->assertSee('NAP Form 2');
        $test->assertSee('NAP Form 3');

        // Test changing RDP monthly filters
        $test->set('rdpCreatedFilter', '10')
            ->set('rdpApprovedFilter', '9')
            ->set('rdpDisposedFilter', '8');

        $this->assertEquals('10', $test->get('rdpCreatedFilter'));
        $this->assertEquals('9', $test->get('rdpApprovedFilter'));
        $this->assertEquals('8', $test->get('rdpDisposedFilter'));

        // Test opening modal for RDP Created
        $test->call('openModalWithCard', 'rdp_created')
            ->set('modalTab', 'records');
        $test->assertStatus(200);
        $this->assertEquals('rdp_created', $test->get('modalCard'));
        $test->assertSee('RDP Subsystem');
        $test->assertSee('Records Created');

        // Test opening modal for RDP Approved
        $test->call('openModalWithCard', 'rdp_approved');
        $test->assertStatus(200);
        $this->assertEquals('rdp_approved', $test->get('modalCard'));
        $test->assertSee('Approved Forms');

        // Test opening modal for RDP Disposed
        $test->call('openModalWithCard', 'rdp_disposed');
        $test->assertStatus(200);
        $this->assertEquals('rdp_disposed', $test->get('modalCard'));
        $test->assertSee('Disposed Forms');
    }
}
