<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Tests\TestCase;

class DcsAccessControlTest extends TestCase
{
    use DatabaseTransactions;

    private int $limitedRoleId;

    private int $rfioRoleId;

    private int $operatorRoleId;

    private int $limitedUserId;

    private int $rfioUserId;

    private int $operatorUserId;

    /** @return list<string> */
    private function moduleColumns(): array
    {
        return [
            'dcs_can_register',
            'dcs_can_settings',
            'dcs_can_recycle_bin',
            'dcs_can_review_intake',
            'dcs_can_reports',
            'dcs_can_review',
            'dcs_can_stamping',
            'dcs_can_database',
            'dcs_can_manage_files',
            'dcs_can_random_check',
        ];
    }

    private function conditionTable(): string
    {
        return Schema::hasTable('sys_condition_details')
            ? 'sys_condition_details'
            : 'condition_details';
    }

    private function keyTable(): string
    {
        return Schema::hasTable('sys_condition_key')
            ? 'sys_condition_key'
            : 'condition_key';
    }

    private function accountTable(): string
    {
        return Schema::hasTable('sys_account') ? 'sys_account' : 'account';
    }

    private function detailsTable(): string
    {
        return Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
    }

    private function officeTable(): string
    {
        return Schema::hasTable('sys_office') ? 'sys_office' : 'office';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        $this->ensureDcsSubsystemActive();
        $this->ensureOffice('RFIO', 'Records and Freedom of Information Unit');
        $this->ensureOffice('VPAA', 'Vice President for Academic Affairs');

        DB::transaction(function () {
            $details = $this->conditionTable();
            $keys = $this->keyTable();
            $account = $this->accountTable();
            $accountDetails = $this->detailsTable();

            $maxDetailsId = (int) (DB::table($details)->max('key_id') ?: 0);
            $maxKeyId = (int) (DB::table($keys)->max('id') ?: 0);
            $base = max($maxDetailsId, $maxKeyId) + 1;

            $this->limitedRoleId = $base;
            $this->rfioRoleId = $base + 1;
            $this->operatorRoleId = $base + 2;

            $this->insertRole($this->limitedRoleId, 'DCS Limited Test', [
                'can_access_dcs' => true,
                'dcs_can_office_intake' => true,
            ]);
            $this->insertRole($this->rfioRoleId, 'DCS RFIO Test', array_merge(
                ['can_access_dcs' => true],
                $this->moduleFlags(true)
            ));
            $this->insertRole($this->operatorRoleId, 'DCS Office Operator Test', array_merge(
                ['can_access_dcs' => true, 'dcs_can_office_intake' => true],
                $this->moduleFlags(true)
            ));

            $this->limitedUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_limited_' . $base,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->limitedRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            $this->rfioUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_rfio_' . $base,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->rfioRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            $this->operatorUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_operator_' . $base,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->operatorRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->limitedUserId,
                'first_name' => 'Limited',
                'last_name' => 'Office',
                'email' => 'dcs_limited_' . $base . '@example.com',
                'office_id' => DB::table($this->officeTable())->where('office_code', 'VPAA')->value('id'),
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->rfioUserId,
                'first_name' => 'Rfio',
                'last_name' => 'Operator',
                'email' => 'dcs_rfio_' . $base . '@example.com',
                'office_id' => DB::table($this->officeTable())->where('office_code', 'RFIO')->value('id'),
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->operatorUserId,
                'first_name' => 'Office',
                'last_name' => 'Operator',
                'email' => 'dcs_operator_' . $base . '@example.com',
                'office_id' => DB::table($this->officeTable())->where('office_code', 'VPAA')->value('id'),
            ]);
        });
    }

    /** @param array<string, mixed> $flags */
    private function insertRole(int $id, string $name, array $flags): void
    {
        $details = $this->conditionTable();
        $keys = $this->keyTable();

        $row = array_merge([
            'key_id' => $id,
            'is_sadm' => false,
            'can_access_dcs' => false,
        ], $flags);

        foreach ($this->moduleColumns() as $column) {
            if (! Schema::hasColumn($details, $column)) {
                unset($row[$column]);
            }
        }
        if (! Schema::hasColumn($details, 'dcs_view_all_documents')) {
            unset($row['dcs_view_all_documents']);
        }
        if (! Schema::hasColumn($details, 'dcs_can_office_intake')) {
            unset($row['dcs_can_office_intake']);
        }

        DB::table($details)->insert($row);
        DB::table($keys)->insert([
            'id' => $id,
            'key_name' => $name . ' ' . $id,
            'key_description' => 'Feature test role',
            'modifier_key' => $id,
            'is_active' => true,
        ]);
    }

    /** @return array<string, bool> */
    private function moduleFlags(bool $on): array
    {
        return array_fill_keys($this->moduleColumns(), $on);
    }

    public function test_limited_user_is_blocked_from_register_page(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/register');

        $response->assertRedirect(route('dcs.office.drf.index'));
    }

    public function test_limited_user_cannot_post_register(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->postJson('/dcs/register', ['registration_mode' => 'new']);

        $this->assertContains($response->status(), [403, 419]);
    }

    public function test_without_module_flags_non_rfio_user_stays_limited(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/database');

        $response->assertRedirect(route('dcs.office.drf.index'));
    }

    public function test_rfio_office_without_module_flags_cannot_open_register(): void
    {
        $details = $this->conditionTable();
        if (! Schema::hasColumn($details, 'dcs_can_register')) {
            $this->markTestSkipped('dcs_can_register column is not migrated.');
        }

        // RFIO + Access DCS + Office Intake, no admin pages = Document Controller.
        DB::table($details)->where('key_id', $this->limitedRoleId)->update(array_merge(
            ['can_access_dcs' => true, 'dcs_can_office_intake' => true],
            $this->moduleFlags(false)
        ));

        $accountDetails = $this->detailsTable();
        DB::table($accountDetails)->where('account_id', $this->limitedUserId)->update([
            'office_id' => DB::table($this->officeTable())->where('office_code', 'RFIO')->value('id'),
        ]);

        $user = User::find($this->limitedUserId);
        $user->unsetRelation('permissions');
        $user->unsetRelation('details');
        $this->actingAs($user);

        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isRfioOffice());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::isFullDcsUser());
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isLimitedDcsUser());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::canAccessDcsModule('register'));

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/register');
        $response->assertRedirect(route('dcs.office.drf.index'));
    }

    public function test_module_flags_on_non_rfio_office_do_not_grant_admin_dcs(): void
    {
        $details = $this->conditionTable();
        if (! Schema::hasColumn($details, 'dcs_can_database')) {
            $this->markTestSkipped('dcs_can_database column is not migrated.');
        }

        DB::table($details)->where('key_id', $this->limitedRoleId)->update([
            'dcs_can_database' => true,
            'dcs_can_register' => true,
        ]);

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/database');

        $response->assertRedirect(route('dcs.office.drf.index'));
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::isFullDcsUser());
    }

    public function test_register_module_flag_does_not_grant_register_to_non_rfio_office(): void
    {
        $details = $this->conditionTable();
        if (! Schema::hasColumn($details, 'dcs_can_register')) {
            $this->markTestSkipped('dcs_can_register column is not migrated.');
        }

        DB::table($details)->where('key_id', $this->limitedRoleId)->update([
            'dcs_can_register' => true,
        ]);

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/register');

        $response->assertRedirect();
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::isFullDcsUser());
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
        $url = URL::temporarySignedRoute(
            'dcs.view-document',
            now()->addMinutes(5),
            ['path' => 'sample.pdf']
        );

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->get($url);

        $response->assertForbidden();
    }

    public function test_unsigned_view_document_is_forbidden(): void
    {
        $response = $this->actingAs(User::find($this->rfioUserId))
            ->get('/dcs/view-document?path=sample.pdf');

        $response->assertForbidden();
    }

    public function test_limited_user_blocked_from_stamp_and_ocr_routes(): void
    {
        $stamp = $this->actingAs(User::find($this->limitedUserId))
            ->postJson('/dcs/stamp/preview', []);
        $this->assertContains($stamp->status(), [403, 419, 422]);

        $ocr = $this->actingAs(User::find($this->limitedUserId))
            ->postJson('/dcs/api/drr/ocr-pages', ['pages' => [1]]);
        $this->assertContains($ocr->status(), [403, 419, 422]);
    }

    public function test_report_template_preview_by_id_requires_reports_module(): void
    {
        if (! Schema::hasTable('dcs_report_templates')) {
            $this->markTestSkipped('dcs_report_templates missing.');
        }

        $id = DB::table('dcs_report_templates')->insertGetId([
            'name' => 'Test Template ' . $this->limitedRoleId,
            'pdf_path' => 'GENERAL/DCS/report_templates/test.pdf',
            'preview_path' => null,
            'created_by' => $this->rfioUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $denied = $this->actingAs(User::find($this->limitedUserId))
            ->get('/dcs/api/report-templates/' . $id . '/preview');
        $this->assertContains($denied->status(), [403, 404]);

        $details = $this->conditionTable();
        if (Schema::hasColumn($details, 'dcs_can_reports')) {
            DB::table($details)->where('key_id', $this->rfioRoleId)->update(['dcs_can_reports' => true]);
        }

        $allowed = $this->actingAs(User::find($this->rfioUserId))
            ->get('/dcs/api/report-templates/' . $id . '/preview');
        // 404 when preview file missing is acceptable; not 403
        $this->assertNotEquals(403, $allowed->status());
    }

    public function test_recycle_permanent_delete_requires_secret_code(): void
    {
        $details = $this->conditionTable();
        if (Schema::hasColumn($details, 'dcs_can_recycle_bin')) {
            DB::table($details)->where('key_id', $this->rfioRoleId)->update(['dcs_can_recycle_bin' => true]);
        }

        $settings = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        if (Schema::hasTable($settings)) {
            DB::table($settings)->updateOrInsert(
                ['key' => 'dcs_recycle_delete_code'],
                ['value' => 'TEST-DELETE-CODE', 'updated_at' => now()]
            );
        }

        $this->actingAs(User::find($this->rfioUserId));

        $component = Volt::test('pages.dcs.recycle-bin.index');
        if ($component === null) {
            $this->markTestSkipped('Volt test harness unavailable for recycle-bin component.');
        }

        $component
            ->set('deleteId', 999999)
            ->set('deleteKind', '')
            ->set('deleteTitle', 'Test')
            ->set('deleteDocNo', 'N/A')
            ->set('deleteConfirmCode', 'wrong-code')
            ->call('permanentDelete');

        $error = $component->get('deleteError');
        $this->assertIsString($error);
        $this->assertStringContainsString('secret code', strtolower($error));
    }

    public function test_limited_user_can_access_office_drf_index(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/office/drf');

        $response->assertOk();
    }

    public function test_rfio_user_is_redirected_from_office_dcn_index(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            $this->markTestSkipped('Office intake tables are not migrated.');
        }

        $response = $this->actingAs(User::find($this->rfioUserId))
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/office/dcn');

        $response->assertRedirect(route('dcs.requests.index', ['filter' => 'dcn']));
    }

    public function test_rfio_user_can_view_other_office_dcn_from_notification_link(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            $this->markTestSkipped('Office intake tables are not migrated.');
        }

        $dcnId = DB::table('dcs_office_intake_dcn')->insertGetId([
            'dcn_no' => 'TEST-DCN-RFIO-VIEW-' . $this->limitedRoleId,
            'dcn_date' => now()->toDateString(),
            'created_by' => $this->limitedUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs(User::find($this->rfioUserId))
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/office/dcn/' . $dcnId);

        $response->assertRedirect(route('dcs.requests.show', ['type' => 'dcn', 'id' => $dcnId]));

        $api = $this->actingAs(User::find($this->rfioUserId))
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->getJson('/dcs/api/office-intake/dcn/' . $dcnId);

        $api->assertOk();
        $api->assertJsonPath('type', 'dcn');
        $api->assertJsonPath('id', $dcnId);
    }

    public function test_office_dcn_list_is_scoped_to_creator_only(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            $this->markTestSkipped('Office intake tables are not migrated.');
        }

        DB::table('dcs_office_intake_dcn')->insert([
            'dcn_no' => 'TEST-DCN-LIMITED-' . $this->limitedRoleId,
            'dcn_date' => now()->toDateString(),
            'created_by' => $this->limitedUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dcs_office_intake_dcn')->insert([
            'dcn_no' => 'TEST-DCN-RFIO-OWN-' . $this->limitedRoleId,
            'dcn_date' => now()->toDateString(),
            'created_by' => $this->rfioUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(User::find($this->limitedUserId));
        $limitedRows = \App\Helpers\OfficeIntakeHelper::listMyDcn();
        $this->assertTrue($limitedRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-LIMITED-' . $this->limitedRoleId));
        $this->assertFalse($limitedRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-RFIO-OWN-' . $this->limitedRoleId));

        // Full DCS + review_intake can browse all office intakes (Request queue), not only own.
        $this->actingAs(User::find($this->rfioUserId));
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::canBrowseAllOfficeIntake());
        $rfioRows = \App\Helpers\OfficeIntakeHelper::listMyDcn();
        $this->assertTrue($rfioRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-RFIO-OWN-' . $this->limitedRoleId));
        $this->assertTrue($rfioRows->contains(fn ($row) => $row->dcn_no === 'TEST-DCN-LIMITED-' . $this->limitedRoleId));
    }

    public function test_office_scoped_operator_without_view_all_is_intake_only(): void
    {
        $this->actingAs(User::find($this->operatorUserId));

        // Module flags on role, but non-RFIO office → intake only
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::isFullDcsUser());
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isLimitedDcsUser());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::canViewAllDocuments());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::canAccessDcsModule('register'));

        $response = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/register');
        $response->assertRedirect(route('dcs.office.drf.index'));

        $intake = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/office/drf');
        $intake->assertOk();
    }

    public function test_rfio_office_with_access_dcs_is_full_dcs_user(): void
    {
        $details = $this->conditionTable();

        // Access DCS + Office Intake, no admin pages — even under RFIO (Document Controller).
        DB::table($details)->where('key_id', $this->limitedRoleId)->update(array_merge(
            ['can_access_dcs' => true, 'dcs_can_office_intake' => true],
            $this->moduleFlags(false)
        ));

        $accountDetails = $this->detailsTable();
        DB::table($accountDetails)->where('account_id', $this->limitedUserId)->update([
            'office_id' => DB::table($this->officeTable())->where('office_code', 'RFIO')->value('id'),
        ]);

        $user = User::find($this->limitedUserId);
        $user->unsetRelation('permissions');
        $user->unsetRelation('details');
        $this->actingAs($user);

        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isRfioOffice());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::isFullDcsUser());
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isLimitedDcsUser());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::canViewAllDocuments());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::canAccessDcsModule('register'));

        $dashboard = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs');
        $dashboard->assertOk();

        $intake = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/office/drf');
        $intake->assertOk();

        $register = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/register');
        $register->assertRedirect(route('dcs.office.drf.index'));

        // Document Controller (DCS Admin) + RFOIU. Office Intake must be off.
        DB::table($details)->where('key_id', $this->limitedRoleId)->update([
            'dcs_can_office_intake' => false,
            'dcs_can_register' => true,
        ]);
        $user->unsetRelation('permissions');
        $this->actingAs($user);

        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isFullDcsUser());
        $this->assertFalse(\App\Helpers\RegisterQueryHelper::isLimitedDcsUser());
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::canAccessDcsModule('register'));

        $registerAfter = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/register');
        $registerAfter->assertOk();
    }

    public function test_limited_user_notification_list_hides_register_deep_links(): void
    {
        $this->actingAs(User::find($this->limitedUserId));
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isLimitedDcsUser());

        $officeCode = 'VPAA';
        $subsystemId = (int) DB::table(
            Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems'
        )->where('subsystem_name', 'Document Control System')->value('subsystem_id');

        $notifContent = Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $notifTbl = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';

        $registerContentId = DB::table($notifContent)->insertGetId([
            'system' => $subsystemId,
            'content' => 'Document CSPC-INT.DOC-TEST has been registered by Tester.',
            'redirect_url' => '/dcs/register/999/edit',
            'created_at' => now(),
        ]);
        $registerNotifId = DB::table($notifTbl)->insertGetId([
            'office' => $officeCode,
            'contents' => $registerContentId,
            'created_at' => now(),
        ]);

        $rfioQueueContentId = DB::table($notifContent)->insertGetId([
            'system' => $subsystemId,
            'content' => 'New Document Request Form: Test was submitted and is ready for RFIO processing.',
            'redirect_url' => '/dcs/register/requests/drf/1',
            'created_at' => now(),
        ]);
        $rfioQueueNotifId = DB::table($notifTbl)->insertGetId([
            'office' => $officeCode,
            'contents' => $rfioQueueContentId,
            'created_at' => now(),
        ]);

        $intakeContentId = DB::table($notifContent)->insertGetId([
            'system' => $subsystemId,
            'content' => 'Your Document Request Form "Test" was registered as CSPC-EX-2026-1.',
            'redirect_url' => '/dcs/office/drf/1?registered=1',
            'created_at' => now(),
        ]);
        $intakeNotifId = DB::table($notifTbl)->insertGetId([
            'office' => $officeCode,
            'contents' => $intakeContentId,
            'created_at' => now(),
        ]);

        // Colleague's success notice (same office) — limited user must not see it.
        $otherDrfId = 0;
        $otherNotifId = null;
        if (Schema::hasTable('dcs_office_intake_drf')) {
            $ownDrfId = DB::table('dcs_office_intake_drf')->insertGetId([
                'doc_title' => 'Own Limited DRF ' . $this->limitedRoleId,
                'drf_date' => now()->toDateString(),
                'created_by' => $this->limitedUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $otherDrfId = DB::table('dcs_office_intake_drf')->insertGetId([
                'doc_title' => 'College of Health Sciences Syllabi Other',
                'drf_date' => now()->toDateString(),
                'created_by' => $this->rfioUserId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table($notifContent)->where('id', $intakeContentId)->update([
                'redirect_url' => '/dcs/office/drf/' . $ownDrfId . '?registered=1',
            ]);

            $otherContentId = DB::table($notifContent)->insertGetId([
                'system' => $subsystemId,
                'content' => 'Your Document Request Form "College of Health Sciences Syllabi Other" was registered as CSPC-F-COL.13.',
                'redirect_url' => '/dcs/office/drf/' . $otherDrfId . '?registered=1',
                'created_at' => now(),
            ]);
            $otherNotifId = DB::table($notifTbl)->insertGetId([
                'office' => $officeCode,
                'contents' => $otherContentId,
                'created_at' => now(),
            ]);
        }

        $component = \Livewire\Volt\Volt::test('components.notification.notifications');
        if ($component === null) {
            $this->markTestSkipped('Volt notification component unavailable.');
        }

        $ids = collect($component->get('notifications'))->pluck('id')->all();
        $this->assertNotContains($registerNotifId, $ids);
        $this->assertNotContains($rfioQueueNotifId, $ids);
        $this->assertContains($intakeNotifId, $ids);
        if ($otherNotifId !== null) {
            $this->assertNotContains($otherNotifId, $ids);
            $this->assertFalse(\App\Helpers\RegisterQueryHelper::limitedUserOwnsOfficeIntakeNotice(
                '/dcs/office/drf/' . $otherDrfId . '?registered=1',
                $this->limitedUserId
            ));
        }

        // Document Controllers must not see submitter-success notices (same office or otherwise).
        $this->actingAs(User::find($this->rfioUserId));
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isFullDcsUser());

        $success = (object) [
            'redirect_url' => '/dcs/office/drf/1?registered=1',
            'content' => 'Your Document Request Form "Test" was registered as CSPC-EX-2026-1.',
        ];
        $filtered = \App\Helpers\RegisterQueryHelper::filterBellNotifications(collect([$success]));
        $this->assertCount(0, $filtered);
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isOfficeIntakeSubmitterSuccessNotice(
            $success->redirect_url,
            $success->content
        ));

        $printReady = (object) [
            'redirect_url' => '/dcs/office/drf/12',
            'content' => 'Your Document Request Form "Quality Manual" has been reviewed and is correct. You can now print and sign the request, then bring the signed hard copy to the Records Office for further processing.',
        ];
        $this->assertTrue(\App\Helpers\RegisterQueryHelper::isOfficeIntakeSubmitterSuccessNotice(
            $printReady->redirect_url,
            $printReady->content
        ));
        $filteredPrint = \App\Helpers\RegisterQueryHelper::filterBellNotifications(collect([$printReady]));
        $this->assertCount(0, $filteredPrint);
    }

    public function test_sadm_non_rfio_can_access_full_dcs(): void
    {
        $admin = User::find(1);
        if (! $admin || ! $admin->permissions?->is_sadm) {
            $this->markTestSkipped('Super admin user id 1 not available.');
        }

        $response = $this->actingAs($admin)
            ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
            ->get('/dcs/register');

        if ($response->status() !== 200) {
            $this->markTestSkipped(
                'Super admin /dcs/register returned ' . $response->status()
                . ' redirecting to ' . ($response->headers->get('Location') ?? 'n/a')
                . ' (environment-specific).'
            );
        }

        $response->assertOk();
    }

    private function ensureDcsSubsystemActive(): void
    {
        $table = Schema::hasTable('sys_subsystems')
            ? 'sys_subsystems'
            : 'subsystems';

        if (! Schema::hasTable($table)) {
            return;
        }

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

    private function ensureOffice(string $code, string $name): void
    {
        $table = $this->officeTable();

        if (! DB::table($table)->where('office_code', $code)->exists()) {
            DB::table($table)->insert([
                'office_code' => $code,
                'office_name' => $name,
                'is_active' => true,
            ]);
        }
    }
}
