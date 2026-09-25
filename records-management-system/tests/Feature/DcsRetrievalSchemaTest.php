<?php

namespace Tests\Feature;

use App\Helpers\RegisterPersistHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DcsRetrievalSchemaTest extends TestCase
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

        if (! Schema::hasTable('dcs_document_retrieval')
            || ! Schema::hasTable('dcs_retrieval_offices')
            || ! Schema::hasTable('dcs_document_requests')) {
            $this->markTestSkipped('Retrieval tables are not migrated.');
        }
    }

    public function test_retrieval_has_no_scan_column_and_stores_offices(): void
    {
        $this->assertFalse(Schema::hasColumn('dcs_document_retrieval', 'scanned_retrieval'));
        $this->assertArrayNotHasKey('scannedRet', RegisterPersistHelper::scanFileRules());

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $officeId = (int) (DB::table($officeTbl)->orderBy('id')->value('id') ?: 0);
        if ($officeId < 1) {
            $officeId = (int) DB::table($officeTbl)->insertGetId([
                'office_code' => 'RET-TEST',
                'office_name' => 'Retrieval Test Office',
            ]);
        }

        $accountTbl = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $createdBy = (int) (DB::table($accountTbl)->orderBy('id')->value('id') ?: 0);
        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 0);
        $docTypeId = (int) (DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('id')->value('id') ?: 0);
        if ($createdBy < 1 || $versionId < 1 || $docTypeId < 1) {
            $this->markTestSkipped('DCS type lookup tables are not seeded.');
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

        $retrievalId = (int) DB::table('dcs_document_retrieval')->insertGetId([
            'request_id' => $requestId,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $officeRow = [
            'retrieval_id' => $retrievalId,
            'office_id' => $officeId,
            'copies' => 2,
        ];
        if (Schema::hasColumn('dcs_retrieval_offices', 'created_at')) {
            $officeRow['created_at'] = $now;
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'updated_at')) {
            $officeRow['updated_at'] = $now;
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')) {
            $officeRow['retrieval_status'] = 'retrieved';
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date')) {
            $officeRow['retrieval_date'] = $now->toDateString();
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_time')) {
            $officeRow['retrieval_time'] = $now->format('H:i:s');
        }
        DB::table('dcs_retrieval_offices')->insert($officeRow);

        $this->assertDatabaseHas('dcs_document_retrieval', [
            'id' => $retrievalId,
            'request_id' => $requestId,
        ]);
        $this->assertDatabaseHas('dcs_retrieval_offices', [
            'retrieval_id' => $retrievalId,
            'office_id' => $officeId,
            'copies' => 2,
        ]);
        $this->assertNotContains('scanned_retrieval', Schema::getColumnListing('dcs_document_retrieval'));
    }
}
