<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use App\Services\RdpRetentionService;

class SupersedeRolloverTest extends TestCase
{
    public function test_supersede_rollover()
    {
        DB::beginTransaction();
        try {
            $kwId = DB::table('rdp_remark_key_triggers')->where('key_word', 'If')->value('id');
            if (!$kwId) {
                $kwId = DB::table('rdp_remark_key_triggers')->insertGetId(['key_word' => 'If', 'is_action_before' => false]);
            }

            $remId = DB::table('rdp_remarks_logic')->insertGetId([
                'remarks_value' => 'Retain 5 years after revised',
                'key_word_id' => $kwId,
                'storage_period_backup' => '5 years',
                'total_period_backup' => '5 years',
            ]);

            $seriesId = DB::table('rdp_record_series')->insertGetId([
                'series_title' => 'Test Manuals Series',
                'is_retention_period_permanent' => true,
                'is_superseding' => true,
                'remarks' => $remId,
                'is_active' => true,
            ]);

            // 1. Insert 2025 record
            $rec1Id = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description' => 'MANUAL 2025 EDITION',
                'office_own' => 'ICTU',
                'is_draft' => false,
                'is_active' => true,
            ]);
            DB::table('rdp_period_covered')->insert([
                'period_owner' => $rec1Id,
                'date_covered' => '2025',
            ]);

            // 2. Insert 2026 record
            $rec2Id = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description' => 'MANUAL 2026 EDITION',
                'office_own' => 'ICTU',
                'is_draft' => false,
                'is_active' => true,
            ]);
            DB::table('rdp_period_covered')->insert([
                'period_owner' => $rec2Id,
                'date_covered' => '2026',
            ]);

            // 3. Trigger rollover
            $superseded = RdpRetentionService::supersedePriorRecords($rec2Id);

            $this->assertEquals([$rec1Id], $superseded);

            $updatedRec1 = DB::table('rdp_record')->where('id', $rec1Id)->first();
            $updatedRec2 = DB::table('rdp_record')->where('id', $rec2Id)->first();

            $this->assertNotNull($updatedRec1->action_date);
            $this->assertStringContainsString('[Revised]', $updatedRec1->description);
            $this->assertNull($updatedRec2->action_date);

            // 4. Verify retention immunity resolution
            $imm1 = RdpRetentionService::resolveImmunity(
                null, null, 'Permanent', true,
                'Retain 5 years after revised', $kwId, false,
                null, '5 years', '5 years', $updatedRec1->action_date
            );
            $this->assertFalse($imm1->effective_is_perm);
            $this->assertEquals('5 years', $imm1->effective_storage);
            $this->assertTrue($imm1->action_date_triggered);

            // 5. Test out-of-order backlog: Backlog 2024 arrives while 2026 is active.
            // It should be recognized as an older edition and marked revised upon arrival.
            $rec0Id = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description' => 'MANUAL 2024 BACKLOG',
                'office_own' => 'ICTU',
                'is_draft' => false,
                'is_active' => true,
            ]);
            DB::table('rdp_period_covered')->insert([
                'period_owner' => $rec0Id,
                'date_covered' => '2024',
            ]);
            $supersededBacklog = RdpRetentionService::supersedePriorRecords($rec0Id);
            $this->assertEquals([$rec0Id], $supersededBacklog, 'Older backlog record is automatically marked revised upon arrival');

            $updatedRec0 = DB::table('rdp_record')->where('id', $rec0Id)->first();
            $this->assertNotNull($updatedRec0->action_date);
            $this->assertStringContainsString('[Revised]', $updatedRec0->description);

            // Active 2026 must still remain active!
            $rec2StillActive = DB::table('rdp_record')->where('id', $rec2Id)->first();
            $this->assertNull($rec2StillActive->action_date);

            // 6. Test office scope: Record for HRMDU should not supersede ICTU!
            $recHrId = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description' => 'MANUAL 2027 HR EDITION',
                'office_own' => 'HRMDU',
                'is_draft' => false,
                'is_active' => true,
            ]);
            DB::table('rdp_period_covered')->insert([
                'period_owner' => $recHrId,
                'date_covered' => '2027',
            ]);
            $supersededHr = RdpRetentionService::supersedePriorRecords($recHrId);
            $this->assertEmpty($supersededHr, 'HR record should not supersede ICT record');

            // 7. Verify consecutive manual additions (2025 -> 2026 -> 2027)
            $rec3Id = DB::table('rdp_record')->insertGetId([
                'record_series_id' => $seriesId,
                'description' => 'MANUAL 2027 EDITION',
                'office_own' => 'ICTU',
                'is_draft' => false,
                'is_active' => true,
            ]);
            DB::table('rdp_period_covered')->insert([
                'period_owner' => $rec3Id,
                'date_covered' => '2027',
            ]);
            $superseded2027 = RdpRetentionService::supersedePriorRecords($rec3Id);
            $this->assertEquals([$rec2Id], $superseded2027, 'Adding 2027 must supersede active 2026');

            $updatedRec2After = DB::table('rdp_record')->where('id', $rec2Id)->first();
            $updatedRec3 = DB::table('rdp_record')->where('id', $rec3Id)->first();
            $this->assertNotNull($updatedRec2After->action_date);
            $this->assertStringContainsString('[Revised]', $updatedRec2After->description);
            $this->assertNull($updatedRec3->action_date);

        } finally {
            DB::rollBack();
        }
    }
}
