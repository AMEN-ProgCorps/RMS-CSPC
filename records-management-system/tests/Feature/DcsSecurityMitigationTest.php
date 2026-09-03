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

    private int $dtsOnlyRoleId;

    private int $dtsOnlyUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureDcsSubsystemActive();
        $this->ensureDtsSubsystemActive();
        $this->ensureOffice('VPAA', 'Vice President for Academic Affairs');

        $conditionDetails = $this->rmsTable('condition_details');
        $conditionKey = $this->rmsTable('condition_key');
        $account = $this->rmsTable('account');
        $accountDetails = $this->rmsTable('account_details');
        $office = $this->rmsTable('office');

        DB::transaction(function () use ($conditionDetails, $conditionKey, $account, $accountDetails, $office) {
            $maxDetailsId = (int) (DB::table($conditionDetails)->max('key_id') ?: 0);
            $maxKeyId = (int) (DB::table($conditionKey)->max('id') ?: 0);

            $this->limitedDcsRoleId = max($maxDetailsId, $maxKeyId) + 1;
            $this->dtsOnlyRoleId = $this->limitedDcsRoleId + 1;

            DB::table($conditionDetails)->insert([
                'key_id' => $this->limitedDcsRoleId,
                'is_sadm' => false,
                'can_access_dcs' => true,
            ]);
            DB::table($conditionKey)->insert([
                'id' => $this->limitedDcsRoleId,
                'key_name' => 'DCS Limited Test Role ' . $this->limitedDcsRoleId,
                'key_description' => 'Feature test role',
                'modifier_key' => $this->limitedDcsRoleId,
                'is_active' => true,
            ]);

            $this->limitedDcsUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_limited_' . $this->limitedDcsRoleId,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->limitedDcsRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->limitedDcsUserId,
                'first_name' => 'Limited',
                'last_name' => 'Dcs',
                'email' => 'dcs_limited_' . $this->limitedDcsRoleId . '@example.com',
                'office_id' => DB::table($office)->where('office_code', 'VPAA')->value('id'),
            ]);

            DB::table($conditionDetails)->insert([
                'key_id' => $this->dtsOnlyRoleId,
                'is_sadm' => false,
                'can_access_dts' => true,
                'can_access_dcs' => false,
            ]);
            DB::table($conditionKey)->insert([
                'id' => $this->dtsOnlyRoleId,
                'key_name' => 'DTS Only Test Role ' . $this->dtsOnlyRoleId,
                'key_description' => 'Feature test role',
                'modifier_key' => $this->dtsOnlyRoleId,
                'is_active' => true,
            ]);

            $this->dtsOnlyUserId = DB::table($account)->insertGetId([
                'username' => 'dts_only_' . $this->dtsOnlyRoleId,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->dtsOnlyRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->dtsOnlyUserId,
                'first_name' => 'Dts',
                'last_name' => 'Only',
                'email' => 'dts_only_' . $this->dtsOnlyRoleId . '@example.com',
                'office_id' => DB::table($office)->where('office_code', 'VPAA')->value('id'),
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

    public function test_dts_view_document_cannot_serve_dcs_paths(): void
    {
        $user = User::find($this->dtsOnlyUserId);
        $this->assertNotNull($user);

        $response = $this->actingAs($user)
            ->get('/dts/view-document?path=' . urlencode('RFIO/DCS/masterlist/secret.pdf'));

        $response->assertForbidden();

        $legacy = $this->actingAs($user)
            ->get('/dts/view-document?path=' . urlencode('scans/masterlist/secret.pdf'));

        $legacy->assertForbidden();
    }

    public function test_get_file_content_does_not_return_dcs_files(): void
    {
        $relative = 'RFIO/DCS/masterlist/dcs-guard-' . $this->limitedDcsRoleId . '.pdf';
        $local = DocumentStorageService::localUploadsPath($relative);
        \Illuminate\Support\Facades\Storage::disk('local')->put($local, '%PDF-1.4 dcs-secret');

        try {
            $this->assertNull(DocumentStorageService::getFileContent($relative));
            $this->assertSame('%PDF-1.4 dcs-secret', DocumentStorageService::getDcsScanContent($relative));
        } finally {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($local);
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
        $table = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $exists = DB::table($table)->where('subsystem_name', 'Document Control System')->exists();
        if (! $exists) {
            DB::table($table)->insert([
                'subsystem_name' => 'Document Control System',
                'subsystem_version' => '1.0',
                'is_active' => true,
            ]);
        } else {
            DB::table($table)
                ->where('subsystem_name', 'Document Control System')
                ->update(['is_active' => true]);
        }
    }

    private function ensureDtsSubsystemActive(): void
    {
        $table = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $exists = DB::table($table)->where('subsystem_name', 'Document Tracking System')->exists();
        if (! $exists) {
            DB::table($table)->insert([
                'subsystem_name' => 'Document Tracking System',
                'subsystem_version' => '1.0',
                'is_active' => true,
            ]);
        } else {
            DB::table($table)
                ->where('subsystem_name', 'Document Tracking System')
                ->update(['is_active' => true]);
        }
    }

    private function rmsTable(string $name): string
    {
        $prefixed = 'sys_' . $name;

        return \Illuminate\Support\Facades\Schema::hasTable($prefixed) ? $prefixed : $name;
    }

    private function ensureOffice(string $code, string $name): void
    {
        $office = $this->rmsTable('office');
        if (! DB::table($office)->where('office_code', $code)->exists()) {
            DB::table($office)->insert([
                'office_code' => $code,
                'office_name' => $name,
                'is_active' => true,
            ]);
        }
    }
}
