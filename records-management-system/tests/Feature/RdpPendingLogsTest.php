<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class RdpPendingLogsTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $roleId;
    private string $tOffice;
    private string $tAccount;
    private string $tAccountDetails;
    private string $roleDetails;
    private string $adminLogsTbl;
    private string $mainPendingTbl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccount = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $roleTable = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $this->roleDetails = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
        $this->adminLogsTbl = Schema::hasTable('sys_admin_logs') ? 'sys_admin_logs' : 'admin_logs';
        $this->mainPendingTbl = Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';

        $this->roleId = (int) (DB::table($roleTable)->max('id') ?: 900) + 1;

        DB::table($this->roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => true,
            'can_access_dts' => true,
            'can_access_rdp' => true,
            'can_access_dcs' => true,
            'can_rdp_access_for_approval' => true,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Test RDP Logs Role ' . $this->roleId,
            'key_description' => 'Role for testing RDP audit logs',
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $officeRow = DB::table($this->tOffice)->first();
        $officeCode = $officeRow ? $officeRow->office_code : 'ICTU';
        $officeId = $officeRow ? $officeRow->id : 1;

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'test_logs_user_' . $this->roleId,
            'password' => bcrypt('secret123'),
            'account_role' => $this->roleId,
            'account_active' => true,
            'account_status' => true,
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Audit',
            'last_name' => 'Tester',
            'email' => 'audit_' . $this->userId . '@test.com',
            'office_id' => $officeId,
        ]);
    }

    public function test_list_page_logs_print_and_download_and_cancel(): void
    {
        $user = User::findOrFail($this->userId);
        $this->actingAs($user);

        // 1. Create a NAP 2 cluster
        $clusterId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $clusterId,
            'cluster_name' => 'Test Cluster ' . $clusterId,
            'office' => 'ICTU',
            'status_id' => 1,
            'is_printed' => false,
            'is_downloaded' => false,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Livewire::test('pages.rdp.pending.list');

        // Test Mark Printed
        $component->set('printCluster', (object)[
            'cluster_id' => $clusterId,
            'cluster_name' => 'Test Cluster ' . $clusterId,
            'form_code' => 'nap2',
            'form_label' => 'NAP Form 2',
            'office' => 'ICTU',
        ]);
        $component->call('markClusterPrinted');

        $this->assertTrue(
            (bool) DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('is_printed')
        );

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Printed NAP Form 2 cluster #{$clusterId} ('Test Cluster {$clusterId}') for office ICTU",
        ]);

        // Test Mark Downloaded
        $component->call('markClusterDownloaded', $clusterId, 'nap2', 'Excel');

        $this->assertTrue(
            (bool) DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('is_downloaded')
        );

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Downloaded NAP Form 2 cluster #{$clusterId} ('Test Cluster {$clusterId}') as local Excel for office ICTU",
        ]);

        // Test Cancel Cluster
        $component->set('selectedCluster', (object)[
            'cluster_id' => $clusterId,
            'cluster_name' => 'Test Cluster ' . $clusterId,
            'form_code' => 'nap2',
            'form_label' => 'NAP Form 2',
            'office' => 'ICTU',
            'status_id' => 1,
        ]);
        $component->call('cancelCluster');

        $this->assertDatabaseMissing('rdp_pending_record_series', [
            'cluster_id' => $clusterId,
        ]);

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Canceled NAP Form 2 cluster #{$clusterId} ('Test Cluster {$clusterId}') for office ICTU via RDP Pending List",
        ]);
    }

    public function test_for_approval_page_logs_evaluation_actions(): void
    {
        $user = User::findOrFail($this->userId);
        $this->actingAs($user);

        // Create a cluster in rdp_pending_record (NAP Form 1)
        $clusterId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rdp_pending_record')->insert([
            'cluster_id' => $clusterId,
            'cluster_name' => 'Eval Test Cluster ' . $clusterId,
            'office' => 'ICTU',
            'status_id' => 1,
            'is_printed' => true,
            'is_downloaded' => false,
            'is_for_nap_one' => true,
            'is_for_nap_three' => false,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Livewire::test('pages.rdp.pending.for-approval');

        // Open approval modal
        $component->call('openApprovalModal', $clusterId, 'nap1');
        $this->assertTrue($component->get('showApprovalModal'));

        // 1. Approve / Verify -> Marks Submitted (status_id = 5)
        $component->call('approveCluster');
        $this->assertEquals(5, DB::table('rdp_pending_record')->where('cluster_id', $clusterId)->value('status_id'));
        $this->assertTrue((bool) DB::table('rdp_pending_record')->where('cluster_id', $clusterId)->value('is_verified'));

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Evaluated & Approved submission cluster #{$clusterId} ('Eval Test Cluster {$clusterId}') [NAP Form 1] from office ICTU — Marked as Submitted",
        ]);

        // Check Notification dispatched on Approval
        $notifTbl = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifContentTbl = Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $this->assertDatabaseHas($notifContentTbl, [
            'system' => 2,
            'content' => "Your Submitted NAP Form 1: 'Eval Test Cluster {$clusterId}' has been approved and will now be recorded in the record office.",
        ]);

        // 2. Reject Form with Remarks
        $component->call('openApprovalModal', $clusterId, 'nap1');
        $component->call('openRejectModal');
        $this->assertTrue($component->get('showRejectModal'));
        $component->set('rejectionRemarks', 'Incorrect period covered on item');
        $component->call('rejectCluster');

        $this->assertEquals(4, DB::table('rdp_pending_record')->where('cluster_id', $clusterId)->value('status_id'));
        $this->assertFalse((bool) DB::table('rdp_pending_record')->where('cluster_id', $clusterId)->value('is_verified'));
        $this->assertEquals('Incorrect period covered on item', DB::table('rdp_pending_record')->where('cluster_id', $clusterId)->value('evaluation_remarks'));

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Rejected submission cluster #{$clusterId} ('Eval Test Cluster {$clusterId}') [NAP Form 1] from office ICTU for correction. Remarks: Incorrect period covered on item",
        ]);

        $this->assertDatabaseHas($notifContentTbl, [
            'system' => 2,
            'content' => "Your Submitted NAP Form 1: 'Eval Test Cluster {$clusterId}' was returned for correction. Reason: Incorrect period covered on item",
        ]);

        // 3. Print Evaluation Report
        $component->call('openPrintModal', $clusterId, 'nap1');
        $component->call('markEvaluationPrinted');

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Printed Evaluation Report for submission cluster #{$clusterId} ('Eval Test Cluster {$clusterId}') [NAP Form 1] from office ICTU",
        ]);
    }

    public function test_for_approval_nap2_verifies_and_unverifies_series_with_audit_logs(): void
    {
        $user = User::findOrFail($this->userId);
        $this->actingAs($user);

        // Create a record series
        $seriesTypeId = DB::table('rdp_record_series_type')->first()?->id ?? 1;
        $retentionId = DB::table('rdp_retention_period')->first()?->id ?? 1;

        $seriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'Curriculum Guides ' . uniqid(),
            'series_type' => $seriesTypeId,
            'retention_period' => $retentionId,
            'recorded_at_office' => 'ICTU',
            'is_verified' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $clusterId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $clusterId,
            'cluster_name' => 'Academic Series Cluster ' . $clusterId,
            'office' => 'ICTU',
            'status_id' => 1,
            'is_printed' => true,
            'is_downloaded' => false,
            'is_verified' => false,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rdp_grouped_record_series')->insert([
            'group_head' => $clusterId,
            'record_series_id' => $seriesId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Livewire::test('pages.rdp.pending.for-approval');

        // Approve NAP 2 -> opens series type modal
        $component->call('openApprovalModal', $clusterId, 'nap2');
        $component->call('approveCluster');
        $this->assertTrue($component->get('showSeriesTypeModal'));

        // Pick series type and confirm approval
        $component->set('selectedSeriesTypeId', $seriesTypeId);
        $component->call('confirmNap2Approval');

        $this->assertEquals(5, DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('status_id'));
        $this->assertTrue((bool) DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('is_verified'));
        $this->assertTrue((bool) DB::table('rdp_record_series')->where('id', $seriesId)->value('is_verified'));

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Evaluated & Approved submission cluster #{$clusterId} ('Academic Series Cluster {$clusterId}') [NAP Form 2] from office ICTU — Marked as Submitted",
        ]);

        $notifContentTbl = Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $this->assertDatabaseHas($notifContentTbl, [
            'system' => 2,
            'content' => "Your Submitted NAP Form 2: 'Academic Series Cluster {$clusterId}' has been approved and will now be recorded in the record office.",
        ]);

        // Reject NAP 2 with remarks
        $component->call('openApprovalModal', $clusterId, 'nap2');
        $component->call('openRejectModal');
        $this->assertTrue($component->get('showRejectModal'));
        $component->set('rejectionRemarks', 'Series description is incomplete');
        $component->call('rejectCluster');

        $this->assertEquals(4, DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('status_id'));
        $this->assertFalse((bool) DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('is_verified'));
        $this->assertFalse((bool) DB::table('rdp_record_series')->where('id', $seriesId)->value('is_verified'));
        $this->assertEquals('Series description is incomplete', DB::table('rdp_pending_record_series')->where('cluster_id', $clusterId)->value('evaluation_remarks'));

        $this->assertDatabaseHas($this->adminLogsTbl, [
            'admin_id' => $this->userId,
            'what_system' => 2,
            'changes' => "Rejected submission cluster #{$clusterId} ('Academic Series Cluster {$clusterId}') [NAP Form 2] from office ICTU for correction. Remarks: Series description is incomplete",
        ]);

        $this->assertDatabaseHas($notifContentTbl, [
            'system' => 2,
            'content' => "Your Submitted NAP Form 2: 'Academic Series Cluster {$clusterId}' was returned for correction. Reason: Series description is incomplete",
        ]);
    }
}
