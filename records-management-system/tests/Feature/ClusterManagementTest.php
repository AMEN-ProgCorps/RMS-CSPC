<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClusterManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Authenticate admin
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        // Clean up database tables for test runs
        DB::table('office')->whereIn('office_code', ['TEST-OFF1', 'TEST-OFF2', 'TEST-OFF3'])->delete();
        DB::table('cluster')->whereIn('cluster_code', ['TEST-CLUST1', 'TEST-CLUST2', 'TEST-CLUST3'])->delete();
    }

    protected function tearDown(): void
    {
        DB::table('office')->whereIn('office_code', ['TEST-OFF1', 'TEST-OFF2', 'TEST-OFF3'])->delete();
        DB::table('cluster')->whereIn('cluster_code', ['TEST-CLUST1', 'TEST-CLUST2', 'TEST-CLUST3'])->delete();
        parent::tearDown();
    }

    /**
     * Test cluster CRUD creation and edit operations.
     */
    public function test_cluster_crud_operations()
    {
        // 1. Create a cluster
        Volt::test('pages.admin.accounts.offices')
            ->call('startCreateCluster')
            ->set('clusterName', 'Test Cluster One')
            ->set('clusterCode', 'TEST-CLUST1')
            ->call('saveClusterChanges')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Cluster created successfully!');

        $this->assertDatabaseHas('cluster', [
            'cluster_name' => 'Test Cluster One',
            'cluster_code' => 'TEST-CLUST1',
            'is_active' => true,
        ]);

        $cluster = DB::table('cluster')->where('cluster_code', 'TEST-CLUST1')->first();

        // 2. Edit the cluster
        Volt::test('pages.admin.accounts.offices')
            ->call('selectCluster', $cluster->id)
            ->assertSet('clusterName', 'Test Cluster One')
            ->assertSet('clusterCode', 'TEST-CLUST1')
            ->set('clusterName', 'Test Cluster One Updated')
            ->set('clusterIsActive', false)
            ->call('saveClusterChanges')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Cluster details updated successfully!');

        $this->assertDatabaseHas('cluster', [
            'id' => $cluster->id,
            'cluster_name' => 'Test Cluster One Updated',
            'is_active' => false,
        ]);
    }

    /**
     * Test cluster deletion and cascade-null effect on offices.
     */
    public function test_cluster_deletion_nullifies_offices()
    {
        // Insert a cluster
        DB::table('cluster')->insert([
            'cluster_name' => 'Cluster to Delete',
            'cluster_code' => 'TEST-CLUST1',
            'is_active' => true,
        ]);

        $cluster = DB::table('cluster')->where('cluster_code', 'TEST-CLUST1')->first();

        // Insert an office belonging to this cluster
        DB::table('office')->insert([
            'office_name' => 'Office in Cluster',
            'office_code' => 'TEST-OFF1',
            'cluster' => 'TEST-CLUST1',
            'is_active' => true,
        ]);

        // Delete the cluster
        Volt::test('pages.admin.accounts.offices')
            ->call('selectCluster', $cluster->id)
            ->call('deleteCluster')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Cluster soft-deleted successfully!');

        // Verify cluster is soft-deleted
        $this->assertDatabaseHas('cluster', [
            'id' => $cluster->id,
            'is_active' => false
        ]);

        // Verify office cluster field was nullified (set to null) instead of deleting the office record
        $this->assertDatabaseHas('office', [
            'office_code' => 'TEST-OFF1',
            'cluster' => null
        ]);
    }

    /**
     * Test successful cluster bulk file import.
     */
    public function test_successful_cluster_bulk_import()
    {
        // Add a test office to be the head
        DB::table('office')->insert([
            'office_name' => 'Head Office Name',
            'office_code' => 'TEST-OFF1',
            'is_active' => true
        ]);

        // Cluster 1: basic
        // Cluster 2: with head as office code and active status true
        // Cluster 3: with head as office name and active status false
        $fileContent = "=Test Cluster One;\nTEST-CLUST1;\n=Test Cluster Two;\nTEST-CLUST2;\nTEST-OFF1;\ntrue;\n=Test Cluster Three;\nTEST-CLUST3;\nHead Office Name;\nfalse;\n";
        $file = UploadedFile::fake()->createWithContent('clusters.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedClusterId', -2)
            ->set('clusterFile', $file)
            ->call('importClusters')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Successfully imported 3 cluster(s) from the file!');

        // Assert database records
        $this->assertDatabaseHas('cluster', [
            'cluster_code' => 'TEST-CLUST1',
            'cluster_name' => 'Test Cluster One',
            'cluster_head' => null,
            'is_active' => true
        ]);
        $this->assertDatabaseHas('cluster', [
            'cluster_code' => 'TEST-CLUST2',
            'cluster_name' => 'Test Cluster Two',
            'cluster_head' => 'TEST-OFF1',
            'is_active' => true
        ]);
        $this->assertDatabaseHas('cluster', [
            'cluster_code' => 'TEST-CLUST3',
            'cluster_name' => 'Test Cluster Three',
            'cluster_head' => 'TEST-OFF1',
            'is_active' => false
        ]);
    }

    public function test_cluster_import_fails_on_missing_equals_prefix()
    {
        $fileContent = "Test Cluster One;\nTEST-CLUST1;\n";
        $file = UploadedFile::fake()->createWithContent('clusters.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedClusterId', -2)
            ->set('clusterFile', $file)
            ->call('importClusters')
            ->assertSet('errorMessage', 'Extraction failed: Line 1 ("Test Cluster One;") must start with \'=\' to indicate the start of a cluster name.');
    }

    /**
     * Test successful office import with optional cluster reference.
     */
    public function test_office_import_with_cluster_code()
    {
        // Create cluster first
        DB::table('cluster')->insert([
            'cluster_name' => 'Existing Cluster',
            'cluster_code' => 'TEST-CLUST1',
            'is_active' => true
        ]);

        // Office 1: basic
        // Office 2: with active status (true) and cluster code
        $fileContent = "=Test Office One;\nTEST-OFF1;\n=Test Office Two;\nTEST-OFF2;\ntrue;\nTEST-CLUST1;\n";
        $file = UploadedFile::fake()->createWithContent('offices.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -2)
            ->set('officeFile', $file)
            ->call('importOffices')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Successfully imported 2 office(s) from the file!');

        $this->assertDatabaseHas('office', [
            'office_code' => 'TEST-OFF1',
            'cluster' => null
        ]);
        $this->assertDatabaseHas('office', [
            'office_code' => 'TEST-OFF2',
            'cluster' => 'TEST-CLUST1'
        ]);
    }

    public function test_cluster_soft_delete()
    {
        // Insert a cluster
        $clusterId = DB::table('cluster')->insertGetId([
            'cluster_name' => 'Delete Test Cluster',
            'cluster_code' => 'DEL-CLUST',
            'is_active' => true
        ]);

        // Soft-delete it
        Volt::test('pages.admin.accounts.offices')
            ->set('selectedClusterId', $clusterId)
            ->call('deleteCluster')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Cluster soft-deleted successfully!');

        // Assert it is deactivated
        $this->assertDatabaseHas('cluster', [
            'id' => $clusterId,
            'is_active' => false
        ]);

        // Cleanup
        DB::table('cluster')->where('id', $clusterId)->delete();
    }

    public function test_cluster_bulk_soft_delete()
    {
        // Insert two clusters
        $id1 = DB::table('cluster')->insertGetId([
            'cluster_name' => 'Bulk 1',
            'cluster_code' => 'BULK1',
            'is_active' => true
        ]);
        $id2 = DB::table('cluster')->insertGetId([
            'cluster_name' => 'Bulk 2',
            'cluster_code' => 'BULK2',
            'is_active' => true
        ]);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedClusterIds', [$id1, $id2])
            ->call('bulkDeleteClusters')
            ->assertHasNoErrors()
            ->assertSet('successMessage', '2 cluster(s) soft-deleted (deactivated) successfully!');

        $this->assertDatabaseHas('cluster', ['id' => $id1, 'is_active' => false]);
        $this->assertDatabaseHas('cluster', ['id' => $id2, 'is_active' => false]);

        // Cleanup
        DB::table('cluster')->whereIn('id', [$id1, $id2])->delete();
    }

    public function test_cluster_reactivation()
    {
        // Insert a deactivated cluster
        $clusterId = DB::table('cluster')->insertGetId([
            'cluster_name' => 'Old Cluster Name',
            'cluster_code' => 'REACT-CODE',
            'is_active' => false
        ]);

        // Attempt to create a new cluster with the same code
        Volt::test('pages.admin.accounts.offices')
            ->set('selectedClusterId', -1)
            ->set('clusterName', 'New Cluster Name')
            ->set('clusterCode', 'REACT-CODE')
            ->call('saveClusterChanges')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'Previously deactivated cluster reactivated and updated successfully!');

        // Assert reactivation and name update
        $this->assertDatabaseHas('cluster', [
            'id' => $clusterId,
            'cluster_name' => 'New Cluster Name',
            'cluster_code' => 'REACT-CODE',
            'is_active' => true
        ]);

        // Cleanup
        DB::table('cluster')->where('id', $clusterId)->delete();
    }
}
