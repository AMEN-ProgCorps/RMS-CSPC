<?php

namespace Tests\Feature;

use App\Helpers\RegisterQueryHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DcsEditRequestTest extends TestCase
{
    use DatabaseTransactions;

    private int $controllerRoleId;

    private int $headRoleId;

    private int $controllerUserId;

    private int $headUserId;

    protected function setUp(): void
    {
        try {
            parent::setUp();
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        $this->ensureDcsSubsystemActive();
        $this->ensureOffice('RFIO', 'Records and Freedom of Information Unit');

        DB::transaction(function () {
            $details = $this->conditionTable();
            $keys = $this->keyTable();
            $account = $this->accountTable();
            $accountDetails = $this->detailsTable();

            $maxDetailsId = (int) (DB::table($details)->max('key_id') ?: 0);
            $maxKeyId = (int) (DB::table($keys)->max('id') ?: 0);
            $base = max($maxDetailsId, $maxKeyId) + 1;

            $this->controllerRoleId = $base;
            $this->headRoleId = $base + 1;

            $controllerFlags = array_merge(
                ['can_access_dcs' => true],
                $this->moduleFlags(true)
            );
            $controllerFlags['dcs_can_recycle_bin'] = false;
            $this->insertRole($this->controllerRoleId, 'DCS Controller Edit Test', $controllerFlags);

            $headFlags = array_merge(
                ['can_access_dcs' => true],
                $this->moduleFlags(true)
            );
            $headFlags['dcs_can_recycle_bin'] = true;
            $this->insertRole($this->headRoleId, 'DCS HEAD Edit Test', $headFlags);

            $this->controllerUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_edit_ctrl_' . $base,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->controllerRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            $this->headUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_edit_head_' . $base,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->headRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            $rfioOfficeId = DB::table($this->officeTable())->where('office_code', 'RFIO')->value('id');

            DB::table($accountDetails)->insert([
                'account_id' => $this->controllerUserId,
                'first_name' => 'Controller',
                'last_name' => 'User',
                'email' => 'dcs_edit_ctrl_' . $base . '@example.com',
                'office_id' => $rfioOfficeId,
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->headUserId,
                'first_name' => 'Head',
                'last_name' => 'Admin',
                'email' => 'dcs_edit_head_' . $base . '@example.com',
                'office_id' => $rfioOfficeId,
            ]);
        });
    }

    private function conditionTable(): string
    {
        return Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';
    }

    private function keyTable(): string
    {
        return Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
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

    /** @return array<string, bool> */
    private function moduleFlags(bool $on): array
    {
        return array_fill_keys($this->moduleColumns(), $on);
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

        DB::table($details)->insert($row);
        DB::table($keys)->insert([
            'id' => $id,
            'key_name' => $name . ' ' . $id,
            'key_description' => 'Document edit access test role',
            'modifier_key' => $id,
            'is_active' => true,
        ]);
    }

    private function ensureOffice(string $code, string $name): void
    {
        $office = $this->officeTable();
        if (DB::table($office)->where('office_code', $code)->exists()) {
            return;
        }
        DB::table($office)->insert([
            'office_code' => $code,
            'office_name' => $name,
            'is_active' => true,
        ]);
    }

    private function ensureDcsSubsystemActive(): void
    {
        $tbl = Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        if (! Schema::hasTable($tbl)) {
            return;
        }
        $id = DB::table($tbl)->where('subsystem_name', 'Document Control System')->value('subsystem_id');
        if ($id) {
            DB::table($tbl)->where('subsystem_id', $id)->update(['is_active' => true]);
        }
    }

    /** @return array{request_id:int} */
    private function insertPublishedDocument(int $createdBy, bool $isDraft = false): array
    {
        if (! Schema::hasTable('dcs_document_requests') || ! Schema::hasTable('dcs_masterlist_registration')) {
            $this->markTestSkipped('DCS document tables are not migrated.');
        }

        $docTypeId = (int) (DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('id')->value('id') ?: 0);
        if ($docTypeId < 1) {
            $docTypeId = (int) DB::table('dcs_doc_types')->insertGetId([
                'doc_type_name' => 'EditReq Type ' . uniqid(),
                'parent_id' => null,
            ]);
        }

        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 0);
        if ($versionId < 1) {
            $this->markTestSkipped('No dcs_version_type row available.');
        }

        $now = now();
        $payload = [
            'version_id' => $versionId,
            'doc_type_id' => $docTypeId,
            'sub_type_id' => null,
            'approval_status' => 'not_applicable',
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $payload['is_draft'] = $isDraft;
        }

        $requestId = (int) DB::table('dcs_document_requests')->insertGetId($payload);

        $ml = [
            'request_id' => $requestId,
            'doc_type_id' => $docTypeId,
            'doc_no' => 'EDIT-REQ-' . $requestId,
            'doc_title' => 'Edit Request Test Doc ' . $requestId,
            'revise_no' => 0,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $ml['revision_status'] = $isDraft ? 'obsolete' : 'latest';
        }
        DB::table('dcs_masterlist_registration')->insert($ml);

        return ['request_id' => $requestId];
    }

    public function test_controller_can_open_published_edit_without_request(): void
    {
        $doc = $this->insertPublishedDocument($this->controllerUserId);
        $this->actingAs(User::find($this->controllerUserId));

        $this->assertTrue(RegisterQueryHelper::canEditDocument($doc['request_id']));

        $response = $this->get('/dcs/register/' . $doc['request_id'] . '/edit');
        $response->assertOk();
        $response->assertDontSee('Request an edit from Update Documents');
        $response->assertDontSee('An edit request is waiting for HEAD Admin approval');
    }

    public function test_controller_can_edit_draft_without_request(): void
    {
        if (! Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $this->markTestSkipped('Drafts are not migrated.');
        }

        $doc = $this->insertPublishedDocument($this->controllerUserId, true);
        $this->actingAs(User::find($this->controllerUserId));

        $this->assertTrue(RegisterQueryHelper::canEditDocument($doc['request_id']));
    }

    public function test_head_can_edit_published_document(): void
    {
        $doc = $this->insertPublishedDocument($this->headUserId);
        $this->actingAs(User::find($this->headUserId));

        $this->assertTrue(RegisterQueryHelper::isDocumentControlHead());
        $this->assertTrue(RegisterQueryHelper::canEditDocument($doc['request_id']));
    }

    public function test_edit_requests_page_is_removed(): void
    {
        $this->actingAs(User::find($this->headUserId))->get('/dcs/edit-requests')->assertNotFound();
    }
}
