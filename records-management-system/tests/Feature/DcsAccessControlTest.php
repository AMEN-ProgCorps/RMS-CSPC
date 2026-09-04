<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DcsAccessControlTest extends TestCase
{
    use DatabaseTransactions;

    private int $roleId;

    private int $limitedUserId;

    private int $rfioUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDcsSubsystemActive();
        $this->ensureOffice('RFIO', 'Records and Freedom of Information Unit');
        $this->ensureOffice('VPAA', 'Vice President for Academic Affairs');

        DB::transaction(function () {
            $maxDetailsId = (int) (DB::table('condition_details')->max('key_id') ?: 0);
            $maxKeyId = (int) (DB::table('condition_key')->max('id') ?: 0);
            $this->roleId = max($maxDetailsId, $maxKeyId) + 1;

            DB::table('condition_details')->insert([
                'key_id' => $this->roleId,
                'is_sadm' => false,
                'can_access_dcs' => true,
            ]);

            DB::table('condition_key')->insert([
                'id' => $this->roleId,
                'key_name' => 'DCS Access Test Role ' . $this->roleId,
                'key_description' => 'Feature test role',
                'modifier_key' => $this->roleId,
                'is_active' => true,
            ]);

            $this->limitedUserId = DB::table('account')->insertGetId([
                'username' => 'dcs_limited_' . $this->roleId,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->roleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            $this->rfioUserId = DB::table('account')->insertGetId([
                'username' => 'dcs_rfio_' . $this->roleId,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->roleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            DB::table('account_details')->insert([
                'account_id' => $this->limitedUserId,
                'first_name' => 'Limited',
                'last_name' => 'Office',
                'email' => 'dcs_limited_' . $this->roleId . '@example.com',
                'office_id' => DB::table('office')->where('office_code', 'VPAA')->value('id'),
            ]);

            DB::table('account_details')->insert([
                'account_id' => $this->rfioUserId,
                'first_name' => 'Rfio',
                'last_name' => 'Operator',
                'email' => 'dcs_rfio_' . $this->roleId . '@example.com',
                'office_id' => DB::table('office')->where('office_code', 'RFIO')->value('id'),
            ]);
        });
    }

    public function test_limited_user_is_blocked_from_register_page(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/register');

        $response->assertRedirect(route('portal'));
    }

    public function test_limited_user_cannot_post_register(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->postJson('/dcs/register', ['registration_mode' => 'new']);

        $this->assertContains($response->status(), [403, 419]);
    }

    public function test_without_view_all_flag_non_rfio_user_stays_limited(): void
    {
        $conditionTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (\Illuminate\Support\Facades\Schema::hasColumn($conditionTable, 'dcs_view_all_documents')) {
            DB::table($conditionTable)->where('key_id', $this->roleId)->update([
                'dcs_view_all_documents' => false,
            ]);
        }

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/database');

        $response->assertRedirect(route('portal'));
    }

    public function test_view_all_flag_grants_full_dcs_to_non_rfio_user(): void
    {
        $conditionTable = \Illuminate\Support\Facades\Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';

        if (! \Illuminate\Support\Facades\Schema::hasColumn($conditionTable, 'dcs_view_all_documents')) {
            $this->markTestSkipped('dcs_view_all_documents column is not migrated.');
        }

        DB::table($conditionTable)->where('key_id', $this->roleId)->update([
            'dcs_view_all_documents' => true,
        ]);

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/database');

        $response->assertOk();
    }

    public function test_rfio_user_can_access_register_page(): void
    {
        $response = $this->actingAs(User::find($this->rfioUserId))
            ->get('/dcs/register');

        $response->assertOk();
    }

    public function test_limited_user_search_without_originator_self_is_forbidden(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->getJson('/dcs/api/documents/search?q=test');

        $response->assertForbidden();
    }

    public function test_limited_user_view_document_is_forbidden(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/view-document?path=sample.pdf');

        $response->assertForbidden();
    }

    public function test_limited_user_can_access_office_drf_index(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/office/drf');

        $response->assertOk();
    }

    public function test_rfio_user_is_redirected_from_office_dcn_index(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            $this->markTestSkipped('Office intake columns are not migrated.');
        }

        $response = $this->actingAs(User::find($this->rfioUserId))
            ->get('/dcs/office/dcn');

        $response->assertRedirect(route('dcs'));
    }

    public function test_rfio_user_can_view_other_office_dcn_from_notification_link(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            $this->markTestSkipped('Office intake columns are not migrated.');
        }

        $dcnId = DB::table('dcs_document_change_notice')->insertGetId([
            'dcn_no' => 'TEST-DCN-RFIO-VIEW-' . $this->roleId,
            'dcn_date' => now()->toDateString(),
            'created_by' => $this->limitedUserId,
            'is_office_intake' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs(User::find($this->rfioUserId))
            ->get('/dcs/office/dcn/' . $dcnId);

        $response->assertRedirect('/dcs?intake=dcn&id=' . $dcnId);

        $api = $this->actingAs(User::find($this->rfioUserId))
            ->getJson('/dcs/api/office-intake/dcn/' . $dcnId);

        $api->assertOk();
        $api->assertJsonPath('type', 'dcn');
        $api->assertJsonPath('id', $dcnId);
        $api->assertJsonPath('title', 'Office DCN Submission');
        $this->assertStringContainsString(
            'TEST-DCN-RFIO-VIEW-' . $this->roleId,
            (string) $api->json('html')
        );
    }

    public function test_office_dcn_list_is_scoped_to_creator_only(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            $this->markTestSkipped('Office intake columns are not migrated.');
        }

        DB::table('dcs_document_change_notice')->insert([
            'dcn_no' => 'TEST-DCN-LIMITED-' . $this->roleId,
            'dcn_date' => now()->toDateString(),
            'created_by' => $this->limitedUserId,
            'is_office_intake' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dcs_document_change_notice')->insert([
            'dcn_no' => 'TEST-DCN-RFIO-OWN-' . $this->roleId,
            'dcn_date' => now()->toDateString(),
            'created_by' => $this->rfioUserId,
            'is_office_intake' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->limitedUserId));
        $limitedRows = \App\Helpers\OfficeIntakeHelper::listMyDcn();
        $this->assertTrue($limitedRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-LIMITED-' . $this->roleId));
        $this->assertFalse($limitedRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-RFIO-OWN-' . $this->roleId));

        $this->actingAs(User::find($this->rfioUserId));
        $rfioRows = \App\Helpers\OfficeIntakeHelper::listMyDcn();
        $this->assertTrue($rfioRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-RFIO-OWN-' . $this->roleId));
        $this->assertFalse($rfioRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-LIMITED-' . $this->roleId));
    }

    public function test_sadm_non_rfio_can_access_full_dcs(): void
    {
        $admin = User::find(1);
        if (! $admin || ! $admin->permissions?->is_sadm) {
            $this->markTestSkipped('Super admin user id 1 not available.');
        }

        $response = $this->actingAs($admin)->get('/dcs/register');

        $response->assertOk();
    }

    private function ensureDcsSubsystemActive(): void
    {
        $exists = DB::table('subsystems')->where('subsystem_name', 'Document Control System')->exists();
        if (! $exists) {
            DB::table('subsystems')->insert([
                'subsystem_name' => 'Document Control System',
                'subsystem_version' => '1.0',
                'is_active' => true,
            ]);
        } else {
            DB::table('subsystems')
                ->where('subsystem_name', 'Document Control System')
                ->update(['is_active' => true]);
        }
    }

    private function ensureOffice(string $code, string $name): void
    {
        if (! DB::table('office')->where('office_code', $code)->exists()) {
            DB::table('office')->insert([
                'office_code' => $code,
                'office_name' => $name,
                'is_active' => true,
            ]);
        }
    }
}
