<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class RdpPendingSortOrderTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $roleId;
    private string $tOffice;
    private string $tAccount;
    private string $tAccountDetails;
    private string $roleDetails;
    private string $mainPendingTbl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccount = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $roleTable = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $this->roleDetails = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
        $this->mainPendingTbl = Schema::hasTable('rdp_main_pending_id') ? 'rdp_main_pending_id' : 'main_pending_id';

        $this->roleId = (int) (DB::table($roleTable)->max('id') ?: 950) + 1;

        DB::table($this->roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => true,
            'can_access_rdp' => true,
            'can_rdp_access_pending' => true,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Sort Order Test Role ' . $this->roleId,
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $officeRow = DB::table($this->tOffice)->first();
        $officeCode = $officeRow ? $officeRow->office_code : 'ICTU';
        $officeId = $officeRow ? $officeRow->id : 1;

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'sort_user_' . $this->roleId,
            'password' => bcrypt('secret123'),
            'account_role' => $this->roleId,
            'account_status' => 1,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Sort',
            'last_name' => 'Tester',
            'email' => 'sort_' . $this->userId . '@test.com',
            'office_id' => $officeId,
        ]);
    }

    public function test_pending_list_sorts_pending_first_then_other_status_with_latest_dates_first(): void
    {
        $user = User::findOrFail($this->userId);
        $this->actingAs($user);

        // Clear or prepare 4 distinct test clusters:
        // 1. Cluster A: Submitted for Approval, created 1 hour ago
        $idSubmittedNew = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => Carbon::now()->subHours(1),
            'updated_at' => Carbon::now()->subHours(1),
        ]);
        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $idSubmittedNew,
            'cluster_name' => 'Submitted Recent ' . $idSubmittedNew,
            'office' => 'ICTU',
            'status_id' => 5, // Submitted
            'is_printed' => true,
            'is_downloaded' => false,
            'created_by' => $this->userId,
            'created_at' => Carbon::now()->subHours(1),
            'updated_at' => Carbon::now()->subHours(1),
        ]);

        // 2. Cluster B: Submitted for Approval, created 5 days ago
        $idSubmittedOld = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);
        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $idSubmittedOld,
            'cluster_name' => 'Submitted Old ' . $idSubmittedOld,
            'office' => 'ICTU',
            'status_id' => 1,
            'is_printed' => true,
            'is_downloaded' => false,
            'created_by' => $this->userId,
            'created_at' => Carbon::now()->subDays(5),
            'updated_at' => Carbon::now()->subDays(5),
        ]);

        // 3. Cluster C: Pending, created 3 days ago
        $idPendingOld = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ]);
        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $idPendingOld,
            'cluster_name' => 'Pending Old ' . $idPendingOld,
            'office' => 'ICTU',
            'status_id' => 1,
            'is_printed' => false,
            'is_downloaded' => false,
            'created_by' => $this->userId,
            'created_at' => Carbon::now()->subDays(3),
            'updated_at' => Carbon::now()->subDays(3),
        ]);

        // 4. Cluster D: Pending, created 10 minutes ago
        $idPendingNew = DB::table($this->mainPendingTbl)->insertGetId([
            'created_at' => Carbon::now()->subMinutes(10),
            'updated_at' => Carbon::now()->subMinutes(10),
        ]);
        DB::table('rdp_pending_record_series')->insert([
            'cluster_id' => $idPendingNew,
            'cluster_name' => 'Pending Recent ' . $idPendingNew,
            'office' => 'ICTU',
            'status_id' => 1,
            'is_printed' => false,
            'is_downloaded' => false,
            'created_by' => $this->userId,
            'created_at' => Carbon::now()->subMinutes(10),
            'updated_at' => Carbon::now()->subMinutes(10),
        ]);

        $component = Livewire::test('pages.rdp.pending.list');
        $clusters = $component->viewData('clusters');

        // Extract our 4 test cluster main_ids in the order they appear
        $testIds = [$idPendingNew, $idPendingOld, $idSubmittedNew, $idSubmittedOld];
        $matchedIds = [];
        foreach ($clusters as $c) {
            if (in_array($c->main_id, $testIds, true)) {
                $matchedIds[] = $c->main_id;
            }
        }

        // Expected Order:
        // 1. Pending Recent (Pending, latest date)
        // 2. Pending Old (Pending, older date)
        // 3. Submitted Recent (Submitted/Other, latest date)
        // 4. Submitted Old (Submitted/Other, older date)
        $this->assertEquals([
            $idPendingNew,
            $idPendingOld,
            $idSubmittedNew,
            $idSubmittedOld,
        ], $matchedIds);
    }
}
