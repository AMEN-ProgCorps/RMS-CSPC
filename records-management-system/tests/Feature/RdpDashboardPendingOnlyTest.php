<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpDashboardPendingOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private string $userOfficeCode;
    private string $tOffice;
    private string $mainPendingTbl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->mainPendingTbl = Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
            $this->userId = $admin->id;
        } else {
            $user = User::first();
            if ($user) {
                Auth::login($user);
                $this->userId = $user->id;
            }
        }

        $user = Auth::user();
        $this->userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? '';
        if (empty($this->userOfficeCode) && !empty($user?->details?->office_id)) {
            $this->userOfficeCode = (string) DB::table($this->tOffice)->where('id', $user->details->office_id)->value('office_code');
        }
        if (empty($this->userOfficeCode)) {
            $this->userOfficeCode = 'ICTU';
        }
    }

    public function test_dashboard_created_forms_only_shows_pending_clusters(): void
    {
        // 1. Create a Pending Form 1 cluster (status_id = 1, is_verified = false)
        $pendingMainId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rdp_pending_record')->insert([
            'cluster_id' => $pendingMainId,
            'cluster_name' => 'Active Pending Verification Cluster',
            'office' => $this->userOfficeCode,
            'is_for_nap_one' => true,
            'is_for_nap_three' => false,
            'status_id' => 1, // Pending Verification
            'is_verified' => false,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create an Approved Form 1 cluster (status_id = 2, is_verified = true)
        $approvedMainId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rdp_pending_record')->insert([
            'cluster_id' => $approvedMainId,
            'cluster_name' => 'Already Approved Cluster Should Be Hidden',
            'office' => $this->userOfficeCode,
            'is_for_nap_one' => true,
            'is_for_nap_three' => false,
            'status_id' => 2, // Approved
            'is_verified' => true,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Create a Pending Form 2 series cluster (status_id = 1, is_verified = false)
        $pendingSeriesMainId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $pendingSeriesMainId,
            'cluster_name' => 'Pending Series Cluster Requiring Action',
            'office' => $this->userOfficeCode,
            'status_id' => 1,
            'is_verified' => false,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Create an Approved Form 2 series cluster (status_id = 2, is_verified = true)
        $approvedSeriesMainId = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $approvedSeriesMainId,
            'cluster_name' => 'Approved Series Cluster Should Be Hidden',
            'office' => $this->userOfficeCode,
            'status_id' => 2,
            'is_verified' => true,
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Volt::test('pages.rdp.index');

        $clusters = collect($component->viewData('createdClusters'));
        $clusterNames = $clusters->pluck('cluster_name')->all();

        $this->assertContains('Active Pending Verification Cluster', $clusterNames);
        $this->assertContains('Pending Series Cluster Requiring Action', $clusterNames);
        $this->assertNotContains('Already Approved Cluster Should Be Hidden', $clusterNames);
        $this->assertNotContains('Approved Series Cluster Should Be Hidden', $clusterNames);
    }

    public function test_dashboard_received_documents_only_shows_pending_status(): void
    {
        // 1. Insert a pending DTS received document matching the user's office
        $pendingDocId = DB::table('rdp_received_documents')->insertGetId([
            'document_code' => 'TEST-PENDING-DOC-001',
            'document_title' => 'Test Document Requiring Action',
            'source_subsystem' => 'DTS',
            'status' => 'pending',
            'origin_office' => $this->userOfficeCode,
            'target_office' => 'RECORDS',
            'date_received' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Insert an appraised DTS received document matching the user's office
        $appraisedDocId = DB::table('rdp_received_documents')->insertGetId([
            'document_code' => 'TEST-APPRAISED-DOC-002',
            'document_title' => 'Test Document Already Appraised',
            'source_subsystem' => 'DTS',
            'status' => 'appraised',
            'origin_office' => $this->userOfficeCode,
            'target_office' => 'RECORDS',
            'date_received' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Volt::test('pages.rdp.index')
            ->set('receivedTab', 'DTS');

        $receivedDocs = collect($component->viewData('receivedDocs'));
        $docCodes = $receivedDocs->pluck('document_code')->all();

        $this->assertContains('TEST-PENDING-DOC-001', $docCodes);
        $this->assertNotContains('TEST-APPRAISED-DOC-002', $docCodes);

        // Every doc in the list must have status 'pending'
        foreach ($receivedDocs as $doc) {
            $this->assertEquals('pending', strtolower($doc->status ?? 'pending'));
        }
    }
}
