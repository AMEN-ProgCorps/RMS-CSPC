<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use App\Models\office;
use App\Models\Cluster;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecycleBinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Authenticate admin (ID 1)
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        // Clean up
        DB::table('office')->whereIn('office_code', ['TEST-OFF-REC1', 'TEST-OFF-REC2'])->delete();
        DB::table('cluster')->whereIn('cluster_code', ['TEST-CLU-REC1'])->delete();
        DB::table('dts_transaction_flow')->whereIn('flow_code', ['TEST-FLO-REC1', 'TEST-FLO-REC2'])->delete();
    }

    protected function tearDown(): void
    {
        DB::table('office')->whereIn('office_code', ['TEST-OFF-REC1', 'TEST-OFF-REC2'])->delete();
        DB::table('cluster')->whereIn('cluster_code', ['TEST-CLU-REC1'])->delete();
        DB::table('dts_transaction_flow')->whereIn('flow_code', ['TEST-FLO-REC1', 'TEST-FLO-REC2'])->delete();
        parent::tearDown();
    }

    /**
     * Test displaying deactivated records in the Recycle Bin.
     */
    public function test_recycle_bin_lists_deactivated_items()
    {
        // Create active and inactive office
        office::create([
            'office_name' => 'Active Office Rec',
            'office_code' => 'TEST-OFF-REC1',
            'is_active' => true,
        ]);
        office::create([
            'office_name' => 'Deactivated Office Rec',
            'office_code' => 'TEST-OFF-REC2',
            'is_active' => false,
        ]);

        // Create inactive cluster
        Cluster::create([
            'cluster_name' => 'Deactivated Cluster Rec',
            'cluster_code' => 'TEST-CLU-REC1',
            'is_active' => false,
        ]);

        $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;

        // Create inactive flows
        DB::table('dts_transaction_flow')->insert([
            'id' => $maxId + 1,
            'flow_name' => 'Deactivated Flow Rec None',
            'flow_code' => 'TEST-FLO-REC1',
            'is_active' => false,
            'flow_use' => 'none',
            'added_by' => 1,
            'date_added' => now(),
        ]);
        DB::table('dts_transaction_flow')->insert([
            'id' => $maxId + 2,
            'flow_name' => 'Deactivated Flow Rec Internal',
            'flow_code' => 'TEST-FLO-REC2',
            'is_active' => false,
            'flow_use' => 'internal',
            'added_by' => 1,
            'date_added' => now(),
        ]);

        // Test Livewire Recycle Bin component lists deactivated items properly
        Volt::test('pages.admin.recycle-bin')
            ->assertSet('activeTab', 'offices')
            // Verify 'with()' results has deactivated office
            ->assertViewHas('deactivatedOffices', function ($offices) {
                return $offices->contains('office_code', 'TEST-OFF-REC2') 
                    && !$offices->contains('office_code', 'TEST-OFF-REC1');
            })
            // Switch tab to clusters
            ->set('activeTab', 'clusters')
            ->assertViewHas('deactivatedClusters', function ($clusters) {
                return $clusters->contains('cluster_code', 'TEST-CLU-REC1');
            })
            // Switch tab to flows
            ->set('activeTab', 'flows')
            ->assertViewHas('deactivatedFlows', function ($flows) {
                $codes = collect($flows)->pluck('flow_code')->toArray();
                return in_array('TEST-FLO-REC1', $codes) && in_array('TEST-FLO-REC2', $codes);
            })
            // Filter by 'internal'
            ->set('flowPurposeFilter', 'internal')
            ->assertViewHas('deactivatedFlows', function ($flows) {
                $codes = collect($flows)->pluck('flow_code')->toArray();
                return !in_array('TEST-FLO-REC1', $codes) && in_array('TEST-FLO-REC2', $codes);
            })
            // Filter by 'none'
            ->set('flowPurposeFilter', 'none')
            ->assertViewHas('deactivatedFlows', function ($flows) {
                $codes = collect($flows)->pluck('flow_code')->toArray();
                return in_array('TEST-FLO-REC1', $codes) && !in_array('TEST-FLO-REC2', $codes);
            });
    }

    /**
     * Test restoring deactivated office reactivates it and logs to admin_logs.
     */
    public function test_restore_office()
    {
        $office = office::create([
            'office_name' => 'Deactivated Office Rec',
            'office_code' => 'TEST-OFF-REC2',
            'is_active' => false,
        ]);

        Volt::test('pages.admin.recycle-bin')
            ->call('restoreOffice', $office->id)
            ->assertSet('successMessage', 'Office restored successfully!');

        $this->assertDatabaseHas('office', [
            'id' => $office->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Restored office from Recycle Bin: Deactivated Office Rec (TEST-OFF-REC2)",
            'what_system' => 3,
        ]);
    }

    /**
     * Test restoring deactivated cluster reactivates it and logs to admin_logs.
     */
    public function test_restore_cluster()
    {
        $cluster = Cluster::create([
            'cluster_name' => 'Deactivated Cluster Rec',
            'cluster_code' => 'TEST-CLU-REC1',
            'is_active' => false,
        ]);

        Volt::test('pages.admin.recycle-bin')
            ->call('restoreCluster', $cluster->id)
            ->assertSet('successMessage', 'Cluster restored successfully!');

        $this->assertDatabaseHas('cluster', [
            'id' => $cluster->id,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Restored cluster from Recycle Bin: Deactivated Cluster Rec (TEST-CLU-REC1)",
            'what_system' => 3,
        ]);
    }

    /**
     * Test restoring deactivated transaction flow reactivates it and logs to admin_logs.
     */
    public function test_restore_flow()
    {
        $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
        $flowId = $maxId + 1;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_name' => 'Deactivated Flow Rec',
            'flow_code' => 'TEST-FLO-REC1',
            'is_active' => false,
            'flow_use' => 'none',
            'added_by' => 1,
            'date_added' => now(),
        ]);

        Volt::test('pages.admin.recycle-bin')
            ->call('restoreFlow', $flowId)
            ->assertSet('successMessage', 'Transaction flow restored successfully!');

        $this->assertDatabaseHas('dts_transaction_flow', [
            'id' => $flowId,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Restored transaction flow from Recycle Bin: Deactivated Flow Rec (TEST-FLO-REC1)",
            'what_system' => 3,
        ]);
    }

    /**
     * Test bulk restoring deactivated items.
     */
    public function test_bulk_restore()
    {
        $office1 = office::create([
            'office_name' => 'Deactivated Office 1',
            'office_code' => 'TEST-OFF-REC1',
            'is_active' => false,
        ]);
        $office2 = office::create([
            'office_name' => 'Deactivated Office 2',
            'office_code' => 'TEST-OFF-REC2',
            'is_active' => false,
        ]);

        Volt::test('pages.admin.recycle-bin')
            ->set('activeTab', 'offices')
            ->set('selectedIds', [$office1->id, $office2->id])
            ->call('bulkRestore')
            ->assertSet('successMessage', 'Successfully restored 2 item(s)!');

        $this->assertDatabaseHas('office', ['id' => $office1->id, 'is_active' => true]);
        $this->assertDatabaseHas('office', ['id' => $office2->id, 'is_active' => true]);
        $this->assertDatabaseHas('admin_logs', [
            'changes' => "Bulk restored 2 office(s) from Recycle Bin: Deactivated Office 1, Deactivated Office 2",
            'what_system' => 3,
        ]);
    }
}
