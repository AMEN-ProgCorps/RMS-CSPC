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
                'dcs_view_all_documents' => true,
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

    public function test_view_all_flag_does_not_grant_full_dcs_to_non_rfio_user(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/database');

        $response->assertRedirect(route('portal'));
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
