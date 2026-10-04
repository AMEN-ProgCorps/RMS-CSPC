<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpNapReportBatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure roles & permissions exist for test user
        DB::table('sys_condition_details')->updateOrInsert(
            ['key_id' => 999],
            [
                'is_sadm'               => true,
                'can_access_rdp'        => true,
                'can_rdp_access_form_1' => true,
                'can_rdp_access_form_3' => true,
            ]
        );

        DB::table('sys_condition_key')->updateOrInsert(
            ['id' => 999],
            [
                'key_name'     => 'Test Super Admin',
                'modifier_key' => 999,
                'is_active'    => true,
            ]
        );

        $user = User::updateOrCreate(
            ['username' => 'test_sadm_user'],
            [
                'password'       => bcrypt('password'),
                'account_role'   => 999,
                'account_status' => 999,
                'account_active' => true,
            ]
        );

        Auth::login($user);
    }

    public function test_batch_record_hierarchy_and_sub_periods_in_nap_form_1(): void
    {
        DB::beginTransaction();
        try {
            // Create a test series
            $seriesId = DB::table('rdp_record_series')->insertGetId([
                'series_title'       => 'TEST BATCH SERIES 2022-2026',
                'is_active'          => true,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            // Create a batch master record
            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description'      => 'ANNUAL BATCH FINANCIAL REPORT',
                'volume'           => '10 BOXES, 2 FOLDERS',
                'records_location' => 'ROOM 101',
                'ispartof_batch'   => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Insert 5 annual sub-periods: 2022 to 2026
            for ($year = 2022; $year <= 2026; $year++) {
                DB::table('rdp_period_covered')->insert([
                    'period_owner'     => $recordId,
                    'date_covered'     => "{$year}-01-01",
                    'date_covered_end' => "{$year}-12-31",
                    'volume'           => "2 Boxes",
                    'created_at'       => now(),
                    'modified_at'      => now(),
                ]);
            }

            $component = Volt::test('pages.rdp.reports.nap-form-1');
            $data = $component->viewData('hierarchyTree');

            // Find our root series
            $targetSeries = collect($data)->firstWhere('id', $seriesId);
            $this->assertNotNull($targetSeries, 'Series should appear in hierarchyTree');

            // The period covered for 2022-2026 should compile to "2022-2026"
            $this->assertEquals('2022-2026', $targetSeries->compiled_period);

            // Check the child record under direct_records
            $this->assertNotEmpty($targetSeries->direct_records);
            $rec = $targetSeries->direct_records[0];
            $this->assertTrue($rec->is_batch);
            $this->assertEquals('ANNUAL BATCH FINANCIAL REPORT', $rec->description);
            $this->assertEquals('Jan 2022 - Dec 2026', $rec->date_covered);
            $this->assertCount(5, $rec->sub_periods);

            // Check sub-period 1 and 5
            $sub1 = $rec->sub_periods[0];
            $this->assertEquals('ANNUAL BATCH FINANCIAL REPORT 1', $sub1->description);
            $this->assertEquals('Jan 2022 - Dec 2022', $sub1->date_covered);
            $this->assertEquals('2 Boxes', $sub1->volume);

            $sub5 = $rec->sub_periods[4];
            $this->assertEquals('ANNUAL BATCH FINANCIAL REPORT 5', $sub5->description);
            $this->assertEquals('Jan 2026 - Dec 2026', $sub5->date_covered);

            // Assert rendering contains sub-period text
            $component->assertSee('ANNUAL BATCH FINANCIAL REPORT 1');
            $component->assertSee('Jan 2022 - Dec 2022');
            $component->assertSee('ANNUAL BATCH FINANCIAL REPORT 5');
            $component->assertSee('Jan 2026 - Dec 2026');
        } finally {
            DB::rollBack();
        }
    }

    public function test_period_covered_shows_present_when_exceeding_current_year(): void
    {
        DB::beginTransaction();
        try {
            $currentYear = Carbon::now()->year;
            $futureYear = $currentYear + 2;

            $seriesId = DB::table('rdp_record_series')->insertGetId([
                'series_title' => 'FUTURE BATCH SERIES',
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description'      => 'ONGOING PROJECT RECORDS',
                'volume'           => '5 BOXES',
                'ispartof_batch'   => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('rdp_period_covered')->insert([
                'period_owner'     => $recordId,
                'date_covered'     => '2022-01-01',
                'date_covered_end' => "{$futureYear}-12-31",
                'volume'           => '5 Boxes',
                'created_at'       => now(),
                'modified_at'      => now(),
            ]);

            $component = Volt::test('pages.rdp.reports.nap-form-1');
            $data = $component->viewData('hierarchyTree');

            $targetSeries = collect($data)->firstWhere('id', $seriesId);
            $this->assertNotNull($targetSeries);
            $this->assertEquals('2022 - Present', $targetSeries->compiled_period);
        } finally {
            DB::rollBack();
        }
    }

    public function test_batch_record_in_nap_form_3(): void
    {
        DB::beginTransaction();
        try {
            $retentionId = DB::table('rdp_retention_period')->insertGetId([
                'active_period'  => '6 months',
                'storage_period' => '6 months',
                'total_period'   => '1 year',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            $seriesId = DB::table('rdp_record_series')->insertGetId([
                'series_title'     => 'FORM 3 BATCH SERIES',
                'retention_period' => $retentionId,
                'is_active'        => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'    => $seriesId,
                'description'         => 'DISPOSAL BATCH BUNDLE',
                'volume'              => '10 BUNDLES',
                'ispartof_batch'      => true,
                'is_draft'            => false,
                'is_active'           => true,
                'transferred_to_nap3' => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            DB::table('rdp_period_covered')->insert([
                'period_owner'     => $recordId,
                'date_covered'     => '2022-01-01',
                'date_covered_end' => '2022-12-31',
                'volume'           => '5 Bundles',
                'created_at'       => now(),
                'modified_at'      => now(),
            ]);
            DB::table('rdp_period_covered')->insert([
                'period_owner'     => $recordId,
                'date_covered'     => '2023-01-01',
                'date_covered_end' => '2023-12-31',
                'volume'           => '5 Bundles',
                'created_at'       => now(),
                'modified_at'      => now(),
            ]);

            $component = Volt::test('pages.rdp.reports.nap-form-3');
            $data = $component->viewData('hierarchyTree');

            $targetSeries = collect($data)->firstWhere('id', $seriesId);
            $this->assertNotNull($targetSeries);
            $this->assertEquals('2022-2023', $targetSeries->compiled_period);

            $this->assertNotEmpty($targetSeries->direct_records);
            $rec = $targetSeries->direct_records[0];
            $this->assertTrue($rec->is_batch);
            $this->assertCount(2, $rec->sub_periods);
            $this->assertEquals('DISPOSAL BATCH BUNDLE 1', $rec->sub_periods[0]->description);
            $this->assertEquals('DISPOSAL BATCH BUNDLE 2', $rec->sub_periods[1]->description);

            $component->assertSee('DISPOSAL BATCH BUNDLE 1');
            $component->assertSee('DISPOSAL BATCH BUNDLE 2');
        } finally {
            DB::rollBack();
        }
    }

    public function test_batch_edit_is_available_on_sub_records_and_not_on_batch_holder(): void
    {
        DB::beginTransaction();
        try {
            $seriesId = DB::table('rdp_record_series')->insertGetId([
                'series_title' => 'TEST BATCH EDIT SERIES',
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description'      => 'BATCH REPORT ITEM',
                'volume'           => '4 BOXES',
                'records_location' => 'ROOM 202',
                'ispartof_batch'   => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $p1Id = DB::table('rdp_period_covered')->insertGetId([
                'period_owner'     => $recordId,
                'date_covered'     => '2022-01-01',
                'date_covered_end' => '2022-12-31',
                'volume'           => '2 Boxes',
                'created_at'       => now(),
                'modified_at'      => now(),
            ]);

            $p2Id = DB::table('rdp_period_covered')->insertGetId([
                'period_owner'     => $recordId,
                'date_covered'     => '2023-01-01',
                'date_covered_end' => '2023-12-31',
                'volume'           => '2 Boxes',
                'created_at'       => now(),
                'modified_at'      => now(),
            ]);

            $component = Volt::test('pages.rdp.reports.nap-form-1');

            // 1. Batch holder row should NOT have openEditSubjectModal($recordId) without periodId
            $component->assertDontSee("wire:click=\"openEditSubjectModal({$recordId})\"", false);

            // 2. Sub-periods SHOULD have openEditSubjectModal with their period ID
            $component->assertSee("wire:click=\"openEditSubjectModal({$recordId}, {$p1Id})\"", false);
            $component->assertSee("wire:click=\"openEditSubjectModal({$recordId}, {$p2Id})\"", false);

            // 3. Open sub-period modal and verify loaded fields
            $component->call('openEditSubjectModal', $recordId, $p1Id);
            $component->assertSet('editingIsBatchSubPeriod', true);
            $component->assertSet('editingPeriodId', $p1Id);
            $component->assertSet('editSubjectDescription', 'BATCH REPORT ITEM 1');
            $component->assertSet('editSubjectVolume', '2 Boxes');

            // 4. Edit sub-period volume and save
            $component->set('editSubjectVolume', '5 Boxes');
            $component->call('saveEditSubject');

            // 5. Verify database updates
            $updatedP1 = DB::table('rdp_period_covered')->where('id', $p1Id)->first();
            $this->assertEquals('5 BOXES', $updatedP1->volume);

            $masterRec = DB::table('rdp_record')->where('id', $recordId)->first();
            // Recompiled volume: 5 Boxes + 2 Boxes = 7 Boxes
            $this->assertStringContainsString('7 BOXES', $masterRec->volume);
        } finally {
            DB::rollBack();
        }
    }
}
