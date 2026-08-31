<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentStorageService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DcsSecurityMitigationTest extends TestCase
{
    use DatabaseTransactions;

    private int $limitedDcsRoleId;

    private int $limitedDcsUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDcsSubsystemActive();
        $this->ensureOffice('VPAA', 'Vice President for Academic Affairs');

        DB::transaction(function () {
            $maxDetailsId = (int) (DB::table('condition_details')->max('key_id') ?: 0);
            $maxKeyId = (int) (DB::table('condition_key')->max('id') ?: 0);

            $this->limitedDcsRoleId = max($maxDetailsId, $maxKeyId) + 1;
            DB::table('condition_details')->insert([
                'key_id' => $this->limitedDcsRoleId,
                'is_sadm' => false,
                'can_access_dcs' => true,
            ]);
            DB::table('condition_key')->insert([
                'id' => $this->limitedDcsRoleId,
                'key_name' => 'DCS Limited Test Role ' . $this->limitedDcsRoleId,
                'key_description' => 'Feature test role',
                'modifier_key' => $this->limitedDcsRoleId,
                'is_active' => true,
            ]);

            $this->limitedDcsUserId = DB::table('account')->insertGetId([
                'username' => 'dcs_limited_' . $this->limitedDcsRoleId,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->limitedDcsRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            DB::table('account_details')->insert([
                'account_id' => $this->limitedDcsUserId,
                'first_name' => 'Limited',
                'last_name' => 'Dcs',
                'email' => 'dcs_limited_' . $this->limitedDcsRoleId . '@example.com',
                'office_id' => DB::table('office')->where('office_code', 'VPAA')->value('id'),
            ]);
        });
    }

    public function test_is_dcs_storage_path_detects_legacy_and_modern_paths(): void
    {
        $this->assertTrue(DocumentStorageService::isDcsStoragePath('scans/masterlist/sample.pdf'));
        $this->assertTrue(DocumentStorageService::isDcsStoragePath('RFIO/DCS/masterlist/DCS-TEST_sample.pdf'));
        $this->assertFalse(DocumentStorageService::isDcsStoragePath('VPAA/DTS/DOC-TEST_sample.pdf'));
    }

    public function test_dcs_scan_url_never_points_to_public_storage(): void
    {
        $url = DocumentStorageService::dcsScanUrl('scans/masterlist/sample.pdf');

        if ($url !== null) {
            $this->assertStringContainsString('/dcs/view-document', $url);
            $this->assertStringNotContainsString('/storage/scans', $url);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_limited_user_document_revisions_returns_forbidden_for_foreign_request(): void
    {
        $response = $this->actingAs(User::find($this->limitedDcsUserId))
            ->getJson('/dcs/api/documents/revisions?request_id=999999');

        $response->assertForbidden();
    }

    public function test_public_scans_htaccess_blocks_direct_access(): void
    {
        $htaccess = base_path('storage/app/public/scans/.htaccess');
        $this->assertFileExists($htaccess);
        $contents = file_get_contents($htaccess);
        $this->assertStringContainsString('Require all denied', $contents);
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
