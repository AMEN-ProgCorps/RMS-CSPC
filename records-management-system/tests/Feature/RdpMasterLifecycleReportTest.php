<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpMasterLifecycleReportTest extends TestCase
{
    private const TYPE_NAME = 'ML TEST TYPE';
    private const ROOT_SERIES = 'ML MASTER ROOT SERIES';
    private const DESC_TRAVEL = 'Travel Order to Pasay ML-ACTIVE';
    private const DESC_BUILD  = 'Building Completion Report ML-REVISED [revised]';

    private int $typeId = 0;
    private int $rootId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first()
            ?? User::first();
        if ($admin) {
            $this->actingAs($admin);
            \Livewire\Livewire::actingAs($admin);
            Auth::login($admin);
        }

        $this->cleanup();

        $now = now();
        $this->typeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name'    => self::TYPE_NAME,
            'shorted_type' => 'MLTT',
            'is_active'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $retId = DB::table('rdp_retention_period')->insertGetId([
            'active_period'  => '5 years',
            'storage_period' => '5 years',
            'total_period'   => '10 years',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $this->rootId = DB::table('rdp_record_series')->insertGetId([
            'series_title'       => self::ROOT_SERIES,
            'series_type'        => $this->typeId,
            'recorded_at_office' => 'Dev',
            'retention_period'   => $retId,
            'is_active'          => true,
            'is_verified'        => true,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $rec1Id = DB::table('rdp_record')->insertGetId([
            'record_series_id' => $this->rootId,
            'description'      => self::DESC_TRAVEL,
            'records_location' => 'CABINET 1',
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_period_covered')->insert([
            'period_owner' => $rec1Id,
            'date_covered' => '2023-01-01',
            'volume'       => '2 volumes',
            'created_at'   => $now,
            'modified_at'  => $now,
        ]);

        $rec2Id = DB::table('rdp_record')->insertGetId([
            'record_series_id' => $this->rootId,
            'description'      => self::DESC_BUILD,
            'records_location' => 'CABINET 2',
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_period_covered')->insert([
            'period_owner' => $rec2Id,
            'date_covered' => '2020-01-01',
            'volume'       => '1 volume',
            'created_at'   => $now,
            'modified_at'  => $now,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        $seriesIds = DB::table('rdp_record_series')
            ->where('series_title', self::ROOT_SERIES)
            ->pluck('id');

        if ($seriesIds->isNotEmpty()) {
            $recIds = DB::table('rdp_record')->whereIn('record_series_id', $seriesIds)->pluck('id');
            DB::table('rdp_period_covered')->whereIn('period_owner', $recIds)->delete();
            DB::table('rdp_record')->whereIn('id', $recIds)->delete();
            DB::table('rdp_record_series')->whereIn('id', $seriesIds)->delete();
        }

        DB::table('rdp_record_series_type')->where('type_name', self::TYPE_NAME)->delete();
    }

    public function test_master_lifecycle_page_renders_successfully(): void
    {
        $response = $this->get('/rdp/reports/master-lifecycle');
        $response->assertStatus(200);
        $response->assertSee('Master Records Lifecycle');
    }

    public function test_master_lifecycle_displays_hierarchy_and_records(): void
    {
        Volt::test('pages.rdp.reports.master-lifecycle')
            ->assertSee(self::ROOT_SERIES)
            ->assertSee(self::DESC_TRAVEL)
            ->assertSee(self::DESC_BUILD);
    }

    public function test_master_lifecycle_search_filtering(): void
    {
        // Multi-word search matching active record
        Volt::test('pages.rdp.reports.master-lifecycle')
            ->set('search', 'Pasay Travel')
            ->assertSee(self::DESC_TRAVEL)
            ->assertDontSee(self::DESC_BUILD);

        // Multi-word search matching revised record
        Volt::test('pages.rdp.reports.master-lifecycle')
            ->set('search', 'Building Completion')
            ->assertSee(self::DESC_BUILD)
            ->assertDontSee(self::DESC_TRAVEL);
    }

    public function test_master_lifecycle_status_pill_filters(): void
    {
        // When filtered to 'revised', only revised records should display
        Volt::test('pages.rdp.reports.master-lifecycle')
            ->call('setStatusFilter', 'revised')
            ->assertSee(self::DESC_BUILD)
            ->assertDontSee(self::DESC_TRAVEL);

        // When filtered to 'active', only active records should display
        Volt::test('pages.rdp.reports.master-lifecycle')
            ->call('setStatusFilter', 'active')
            ->assertSee(self::DESC_TRAVEL)
            ->assertDontSee(self::DESC_BUILD);
    }

    public function test_master_lifecycle_export_csv(): void
    {
        $component = Volt::test('pages.rdp.reports.master-lifecycle');
        $response = $component->call('exportCsv');
        
        $this->assertNotNull($response);
    }
}
