<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RdpBracketSyncService;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpBracketSyncTest extends TestCase
{
    protected ?User $adminUser = null;

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
            $admin = User::first();
        }

        if ($admin && $admin->permissions) {
            $admin->permissions->is_sadm = true;
            $admin->permissions->can_access_rdp_admin = true;
            $admin->permissions->save();
        }

        $this->adminUser = $admin;
        if ($this->adminUser) {
            $this->actingAs($this->adminUser);
        }

        // Test Fixtures for isolated testing database
        $officeTable = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        if (\Illuminate\Support\Facades\Schema::hasTable($officeTable)) {
            DB::table($officeTable)->updateOrInsert(
                ['office_code' => 'VPAA'],
                ['office_name' => 'Office of the Vice President for Academic Affairs', 'is_active' => true]
            );
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series_type')) {
            $typeData = [
                'type_name'   => 'CSPC',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('rdp_record_series_type', 'shorted_type')) {
                $typeData['shorted_type'] = 'CSPC';
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('rdp_record_series_type', 'is_locked')) {
                $typeData['is_locked'] = false;
            }
            DB::table('rdp_record_series_type')->updateOrInsert(['id' => 2], $typeData);
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series_brackets')) {
            DB::table('rdp_record_series_brackets')->updateOrInsert(
                ['id' => 2],
                [
                    'bracket_name'   => 'OFFICE OF THE VICE PRESIDENT FOR ACADEMIC AFFAIRS',
                    'series_type_id' => 2,
                    'is_active'      => true,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]
            );
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('rdp_record_series')) {
            DB::table('rdp_record_series')->updateOrInsert(
                ['series_title' => 'CHECKLIST OF STUDENT GRADES TEST'],
                [
                    'item_number'        => 23,
                    'bracket_id'         => 2,
                    'recorded_at_office' => 'ACADEMIC DEPARTMENT',
                    'is_active'          => true,
                    'is_verified'        => true,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]
            );
        }
    }

    public function test_bracket_sync_service_auto_detects_sub_brackets()
    {
        $result = RdpBracketSyncService::syncAllBrackets();

        $this->assertIsArray($result);

        // Check ACADEMIC DEPARTMENT sub-bracket exists for main bracket 2
        $sub = DB::table('rdp_record_series_sub_brackets')
            ->where('main_bracket_id', 2)
            ->where('sub_name', 'ACADEMIC DEPARTMENT')
            ->first();

        $this->assertNotNull($sub, 'ACADEMIC DEPARTMENT sub-bracket should be created');

        // Check series with recorded_at_office = ACADEMIC DEPARTMENT are linked
        $linkedSeriesCount = DB::table('rdp_record_series')
            ->where('bracket_id', 2)
            ->where('sub_bracket_id', $sub->id)
            ->count();

        $this->assertGreaterThan(0, $linkedSeriesCount);

        // Check office VPAA is mapped in rdp_office_bracket
        $officeMapped = DB::table('rdp_office_bracket')
            ->where('sub_bracket_id', $sub->id)
            ->where('office_code', 'VPAA')
            ->exists();

        $this->assertTrue($officeMapped, 'Office VPAA should be mapped to ACADEMIC DEPARTMENT sub-bracket');
    }

    public function test_brackets_page_renders_detected_compile_brackets()
    {
        // First ensure synced
        RdpBracketSyncService::syncAllBrackets();

        $comp = Volt::test('pages.admin.rdp.brackets')
            ->set('selectedTypeId', 2)
            ->call('selectMainBracket', 2);

        $comp->assertHasNoErrors();
        $comp->assertSee('ACADEMIC DEPARTMENT');
        $comp->assertSee('ser'); // Series badge

        // Select the ACADEMIC DEPARTMENT sub-bracket
        $subId = DB::table('rdp_record_series_sub_brackets')
            ->where('main_bracket_id', 2)
            ->where('sub_name', 'ACADEMIC DEPARTMENT')
            ->value('id');

        $this->assertNotNull($subId);

        $comp->call('selectSubBracket', $subId);
        $comp->assertHasNoErrors();
        $comp->assertSee('VPAA');
    }

    public function test_resolve_sub_bracket_id_creates_new_sub_bracket_on_the_fly()
    {
        $subId = RdpBracketSyncService::resolveSubBracketId(2, 'TEST DISCOVERY LAB');
        $this->assertNotNull($subId);

        $sub = DB::table('rdp_record_series_sub_brackets')->where('id', $subId)->first();
        $this->assertNotNull($sub);
        $this->assertEquals('TEST DISCOVERY LAB', $sub->sub_name);
        $this->assertEquals(2, $sub->main_bracket_id);

        // Calling it again returns the same ID
        $againId = RdpBracketSyncService::resolveSubBracketId(2, 'test discovery lab');
        $this->assertEquals($subId, $againId);
    }
}
