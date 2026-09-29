<?php

namespace Tests\Feature;

use App\Helpers\RegisterPersistHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DcsDrfNoUniqueTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        try {
            parent::setUp();
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        if (! Schema::hasTable('dcs_document_request_form')
            || ! Schema::hasTable('dcs_document_requests')) {
            $this->markTestSkipped('Register DRF tables are not migrated.');
        }
    }

    public function test_drf_no_is_unique_across_register_rows(): void
    {
        $accountTbl = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $createdBy = (int) (DB::table($accountTbl)->orderBy('id')->value('id') ?: 0);
        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 0);
        $docTypeId = (int) (DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('id')->value('id') ?: 0);
        if ($createdBy < 1 || $versionId < 1 || $docTypeId < 1) {
            $this->markTestSkipped('DCS lookup tables are not seeded.');
        }

        $now = now();
        $requestPayload = [
            'version_id' => $versionId,
            'doc_type_id' => $docTypeId,
            'sub_type_id' => null,
            'approval_status' => 'not_applicable',
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $requestPayload['is_draft'] = false;
        }
        $requestId = (int) DB::table('dcs_document_requests')->insertGetId($requestPayload);

        $drfNo = 'DRF-UNIQUE-' . uniqid();
        DB::table('dcs_document_request_form')->insert([
            'request_id' => $requestId,
            'drf_no' => $drfNo,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->assertTrue(RegisterPersistHelper::drfNoTaken($drfNo));
        $this->assertTrue(RegisterPersistHelper::drfNoTaken(strtolower($drfNo)));
        $this->assertFalse(RegisterPersistHelper::drfNoTaken($drfNo, $requestId));

        $blocked = RegisterPersistHelper::rejectDuplicateDrfNo(
            Request::create('/dcs/register', 'POST', ['drfNo' => $drfNo])
        );
        $this->assertNotNull($blocked);
    }
}
