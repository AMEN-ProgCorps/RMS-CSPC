<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpSafeLockTest extends TestCase
{
    private const SERIES_TITLE = 'SAFE LOCK TEST SERIES';
    private const REC_LOCKED   = 'Safe Lock Record Locked Subject';
    private const REC_UNLOCKED = 'Safe Lock Record Unlocked Subject';

    private int $typeId = 0;
    private int $seriesId = 0;
    private int $recLockedId = 0;
    private int $recUnlockedId = 0;
    private int $clusterId = 0;
    private int $adminId = 1;
    private string $office = 'Dev';

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first()
            ?? User::first();
        if ($admin) {
            $this->adminId = (int)$admin->id;
            $this->office = $admin->details?->office_code ?? $admin->details?->office?->office_code ?? 'Dev';
            $this->actingAs($admin);
            \Livewire\Livewire::actingAs($admin);
            Auth::login($admin);
        }

        $this->cleanup();

        $now = now();
        $this->typeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name'    => 'SAFE LOCK TYPE',
            'shorted_type' => 'SLT',
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

        $this->seriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title'       => self::SERIES_TITLE,
            'series_type'        => $this->typeId,
            'recorded_at_office' => $this->office,
            'retention_period'   => $retId,
            'is_active'          => true,
            'is_verified'        => true,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $this->recLockedId = DB::table('rdp_record')->insertGetId([
            'record_series_id' => $this->seriesId,
            'description'      => self::REC_LOCKED,
            'records_location' => 'SHELF A',
            'office_own'       => $this->office,
            'is_active'        => true,
            'is_draft'         => false,
            'transferred_to_nap3' => false,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_period_covered')->insert([
            'period_owner' => $this->recLockedId,
            'date_covered' => '2023-01-01',
            'volume'       => '1 volume',
            'created_at'   => $now,
            'modified_at'  => $now,
        ]);

        $this->recUnlockedId = DB::table('rdp_record')->insertGetId([
            'record_series_id' => $this->seriesId,
            'description'      => self::REC_UNLOCKED,
            'records_location' => 'SHELF B',
            'office_own'       => $this->office,
            'is_active'        => true,
            'is_draft'         => false,
            'transferred_to_nap3' => false,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_period_covered')->insert([
            'period_owner' => $this->recUnlockedId,
            'date_covered' => '2023-01-01',
            'volume'       => '1 volume',
            'created_at'   => $now,
            'modified_at'  => $now,
        ]);

        // Package recLockedId into an active NAP 1 cluster
        $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
        $this->clusterId = DB::table($mainPendingTbl)->insertGetId([
            'status'     => 'UNUSED',
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rdp_pending_record')->insert([
            'cluster_id'       => $this->clusterId,
            'cluster_name'     => 'Active NAP 1 Form Test',
            'status_id'        => 1, // Pending Verification
            'office'           => $this->office,
            'created_by'       => $this->adminId,
            'is_for_nap_one'   => true,
            'is_for_nap_three' => false,
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_grouped_record')->insert([
            'group_head' => $this->clusterId,
            'record_id'  => $this->recLockedId,
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        if ($this->clusterId > 0) {
            DB::table('rdp_grouped_record')->where('group_head', $this->clusterId)->delete();
            DB::table('rdp_pending_record')->where('cluster_id', $this->clusterId)->delete();
            $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
            DB::table($mainPendingTbl)->where('id', $this->clusterId)->delete();
        }

        $series = DB::table('rdp_record_series')
            ->where('series_title', self::SERIES_TITLE)
            ->pluck('id');

        if ($series->isNotEmpty()) {
            $recIds = DB::table('rdp_record')
                ->whereIn('record_series_id', $series)
                ->pluck('id');

            DB::table('rdp_grouped_record')->whereIn('record_id', $recIds)->delete();
            DB::table('rdp_period_covered')->whereIn('period_owner', $recIds)->delete();
            DB::table('rdp_record')->whereIn('id', $recIds)->delete();
            DB::table('rdp_record_series')->whereIn('id', $series)->delete();
        }

        DB::table('rdp_record_series_type')->where('type_name', 'SAFE LOCK TYPE')->delete();
    }

    public function test_nap_form_1_safe_lock_identifies_locked_records_and_excludes_from_selection(): void
    {
        $component = Volt::test('pages.rdp.reports.nap-form-1', ['officeFilter' => $this->office]);

        $tree = $component->viewData('hierarchyTree');
        $this->assertNotEmpty($tree);

        // Find our series in the tree
        $targetSeries = collect($tree)->first(fn($s) => $s->series_title === self::SERIES_TITLE);
        $this->assertNotNull($targetSeries);

        // Locked record must have is_locked = true and locked_status_label = 'Pending'
        $lockedRec = collect($targetSeries->direct_records)->first(fn($r) => $r->id === $this->recLockedId);
        $this->assertNotNull($lockedRec);
        $this->assertTrue((bool)$lockedRec->is_locked);
        $this->assertEquals('Pending', $lockedRec->locked_status_label);

        // Unlocked record must have is_locked = false
        $unlockedRec = collect($targetSeries->direct_records)->first(fn($r) => $r->id === $this->recUnlockedId);
        $this->assertNotNull($unlockedRec);
        $this->assertFalse((bool)($unlockedRec->is_locked ?? false));

        // Selectable IDs on series must ONLY contain unlocked record
        $this->assertEquals([(int)$this->recUnlockedId], array_map('intval', $targetSeries->selectable_record_ids));

        // HTML assertion: locked record renders the check icon with cluster title
        $component->assertSee('Packaged in active form: Active NAP 1 Form Test');
    }

    public function test_nap_form_1_backend_rejects_packaging_already_locked_records(): void
    {
        $component = Volt::test('pages.rdp.reports.nap-form-1', ['officeFilter' => $this->office])
            ->set('selectedIds', [(string)$this->recLockedId])
            ->call('submitClusterCreation');

        $component->assertSet('errorMessage', function($msg) {
            return str_contains($msg, 'already packaged in active form');
        });
    }

    public function test_nap_form_1_series_shows_lock_when_all_records_are_packaged(): void
    {
        // Also package the second record
        DB::table('rdp_grouped_record')->insert([
            'group_head' => $this->clusterId,
            'record_id'  => $this->recUnlockedId,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Volt::test('pages.rdp.reports.nap-form-1', ['officeFilter' => $this->office]);
        $tree = $component->viewData('hierarchyTree');
        $targetSeries = collect($tree)->first(fn($s) => $s->series_title === self::SERIES_TITLE);

        $this->assertNotNull($targetSeries);
        $this->assertTrue((bool)$targetSeries->all_records_locked);
        $this->assertEmpty($targetSeries->selectable_record_ids);
        $component->assertSee('packaged');
    }

    public function test_nap_form_2_safe_lock_and_backend_rejection(): void
    {
        $now = now();
        // NAP Form 2 only lists unverified custom series
        DB::table('rdp_record_series')->where('id', $this->seriesId)->update([
            'is_verified' => false,
        ]);

        $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
        $pId = DB::table($mainPendingTbl)->insertGetId([
            'status'     => 'UNUSED',
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rdp_pending_record_series')->insert([
            'cluster_id'   => $pId,
            'cluster_name' => 'Active RDS Form Test',
            'status_id'    => 1,
            'office'       => $this->office,
            'created_by'   => $this->adminId,
            'is_active'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        DB::table('rdp_grouped_record_series')->insert([
            'group_head'       => $pId,
            'record_series_id' => $this->seriesId,
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $component = Volt::test('pages.rdp.reports.nap-form-2', ['officeFilter' => $this->office]);
        $list = $component->viewData('recordSeriesList');
        $item = collect($list)->first(fn($s) => $s->id === $this->seriesId);

        $this->assertNotNull($item);
        $this->assertTrue((bool)$item->is_locked);
        $this->assertEquals('Pending', $item->locked_status_label);
        $component->assertSee('Packaged in active form: Active RDS Form Test');

        $selectable = $component->viewData('allSelectableRecordIds');
        $this->assertNotContains((string)$this->seriesId, $selectable);

        // Backend rejection
        $component->set('selectedIds', [(string)$this->seriesId])
            ->call('submitClusterCreation');
        $component->assertSet('errorMessage', function($msg) {
            return str_contains($msg, 'already packaged in active form');
        });

        // Cleanup RDS cluster
        DB::table('rdp_grouped_record_series')->where('group_head', $pId)->delete();
        DB::table('rdp_pending_record_series')->where('cluster_id', $pId)->delete();
        DB::table($mainPendingTbl)->where('id', $pId)->delete();
    }

    public function test_nap_form_3_safe_lock_and_backend_rejection(): void
    {
        $now = now();
        // Set period covered to expired date so retention service keeps it expired
        DB::table('rdp_period_covered')->where('period_owner', $this->recLockedId)->update([
            'date_covered'     => '2010-01-01',
            'date_covered_end' => '2010-12-31',
        ]);

        // Mark as transferred to nap 3 so it appears on nap form 3
        DB::table('rdp_record')->where('id', $this->recLockedId)->update([
            'transferred_to_nap3' => true,
        ]);

        $mainPendingTbl = \Illuminate\Support\Facades\Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';
        $pId = DB::table($mainPendingTbl)->insertGetId([
            'status'     => 'UNUSED',
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('rdp_pending_record')->insert([
            'cluster_id'       => $pId,
            'cluster_name'     => 'Active Disposal Form Test',
            'status_id'        => 5, // Submitted
            'office'           => $this->office,
            'created_by'       => $this->adminId,
            'is_for_nap_one'   => false,
            'is_for_nap_three' => true,
            'is_active'        => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_grouped_record')->insert([
            'group_head' => $pId,
            'record_id'  => $this->recLockedId,
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $component = Volt::test('pages.rdp.reports.nap-form-3', ['officeFilter' => $this->office]);
        $tree = $component->viewData('hierarchyTree');
        $targetSeries = collect($tree)->first(fn($s) => $s->series_title === self::SERIES_TITLE);

        $this->assertNotNull($targetSeries);
        $lockedRec = collect($targetSeries->direct_records)->first(fn($r) => $r->id === $this->recLockedId);
        $this->assertNotNull($lockedRec);
        $this->assertTrue((bool)$lockedRec->is_locked);
        $this->assertEquals('Submitted', $lockedRec->locked_status_label);
        $component->assertSee('Packaged in active disposal form: Active Disposal Form Test');

        $selectable = $component->viewData('allSelectableRecordIds');
        $this->assertNotContains((string)$this->recLockedId, $selectable);

        // Backend rejection
        $component->set('selectedIds', [(string)$this->recLockedId])
            ->call('submitClusterCreation');
        $component->assertSet('errorMessage', function($msg) {
            return str_contains($msg, 'already packaged in active disposal form');
        });

        // Cleanup disposal cluster
        DB::table('rdp_grouped_record')->where('group_head', $pId)->delete();
        DB::table('rdp_pending_record')->where('cluster_id', $pId)->delete();
        DB::table($mainPendingTbl)->where('id', $pId)->delete();
    }

    public function test_nap_form_1_batch_mode_shows_check_icon_for_locked_and_checkbox_for_unlocked(): void
    {
        $now = now();
        $batchSubject = 'BATCH COR TESTING';

        // 1. Packaged batch record (e.g. 2023-2025)
        $recPackaged = DB::table('rdp_record')->insertGetId([
            'record_series_id' => $this->seriesId,
            'description'      => $batchSubject,
            'records_location' => 'SHELF BATCH',
            'office_own'       => $this->office,
            'is_active'        => true,
            'is_draft'         => false,
            'ispartof_batch'   => true,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_period_covered')->insert([
            [
                'period_owner' => $recPackaged,
                'date_covered' => '2023-01-01',
                'volume'       => '1 vol',
                'description'  => $batchSubject . ' 2023',
                'created_at'   => $now,
                'modified_at'  => $now,
            ],
            [
                'period_owner' => $recPackaged,
                'date_covered' => '2025-01-01',
                'volume'       => '1 vol',
                'description'  => $batchSubject . ' 2025',
                'created_at'   => $now,
                'modified_at'  => $now,
            ],
        ]);

        // Package into existing active cluster
        DB::table('rdp_grouped_record')->insert([
            'group_head' => $this->clusterId,
            'record_id'  => $recPackaged,
            'is_active'  => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 2. Newly added record with same description (e.g. 2026)
        $recNew = DB::table('rdp_record')->insertGetId([
            'record_series_id' => $this->seriesId,
            'description'      => $batchSubject,
            'records_location' => 'SHELF BATCH',
            'office_own'       => $this->office,
            'is_active'        => true,
            'is_draft'         => false,
            'ispartof_batch'   => false,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        DB::table('rdp_period_covered')->insert([
            'period_owner' => $recNew,
            'date_covered' => '2026-01-01',
            'volume'       => '1 vol',
            'description'  => $batchSubject . ' 2026',
            'created_at'   => $now,
            'modified_at'  => $now,
        ]);

        $component = Volt::test('pages.rdp.reports.nap-form-1', ['officeFilter' => $this->office]);
        $tree = $component->viewData('hierarchyTree');
        $targetSeries = collect($tree)->first(fn($s) => $s->series_title === self::SERIES_TITLE);

        $this->assertNotNull($targetSeries);
        $batchItem = collect($targetSeries->direct_records)->first(fn($r) => $r->description === $batchSubject);
        $this->assertNotNull($batchItem);
        $this->assertTrue((bool)$batchItem->is_batch);

        // Sub-periods: 2026 must be unlocked, 2023 & 2025 must be locked
        $sub2026 = collect($batchItem->sub_periods)->first(fn($s) => str_contains($s->description, '2026'));
        $this->assertNotNull($sub2026);
        $this->assertFalse((bool)$sub2026->is_locked);

        $sub2025 = collect($batchItem->sub_periods)->first(fn($s) => str_contains($s->description, '2025'));
        $this->assertNotNull($sub2025);
        $this->assertTrue((bool)$sub2025->is_locked);

        // Selectable IDs on series must include recNew and exclude recPackaged
        $this->assertContains((string)$recNew, array_map('strval', $targetSeries->selectable_record_ids));
        $this->assertNotContains((string)$recPackaged, array_map('strval', $targetSeries->selectable_record_ids));

        // Cleanup batch records
        DB::table('rdp_grouped_record')->where('record_id', $recPackaged)->delete();
        DB::table('rdp_period_covered')->whereIn('period_owner', [$recPackaged, $recNew])->delete();
        DB::table('rdp_record')->whereIn('id', [$recPackaged, $recNew])->delete();
    }
}
