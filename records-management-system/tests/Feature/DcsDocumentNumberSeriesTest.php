<?php

namespace Tests\Feature;

use App\Helpers\DocumentNumberSeriesHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DcsDocumentNumberSeriesTest extends TestCase
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
    }

    public function test_parse_leaf_series_for_nested_and_simple_numbers(): void
    {
        $nested = DocumentNumberSeriesHelper::parseDocNo('CSPC-F-ACCTG-17B10');
        $this->assertSame('CSPC-F-ACCTG-17B', $nested['series']);
        $this->assertSame(10, $nested['number']);
        $this->assertSame('', $nested['suffix']);

        $simple = DocumentNumberSeriesHelper::parseDocNo('CSPC-F-ACCTG-11');
        $this->assertSame('CSPC-F-ACCTG-', $simple['series']);
        $this->assertSame(11, $simple['number']);

        $letter = DocumentNumberSeriesHelper::parseDocNo('CSPC-F-ACCTG-11A');
        $this->assertSame('CSPC-F-ACCTG-', $letter['series']);
        $this->assertSame(11, $letter['number']);
        $this->assertSame('A', $letter['suffix']);
    }

    public function test_next_drf_and_dcn_use_year_and_month_format(): void
    {
        $this->assertSame('2026-001', DocumentNumberSeriesHelper::nextDrfNo([], 2026));
        $this->assertSame('2026-016', DocumentNumberSeriesHelper::nextDrfNo(['2026-001', '2026-015'], 2026));
        $this->assertSame('2026-001', DocumentNumberSeriesHelper::nextDrfNo(['CSPC-F-4253', 'DRF-087', '2025-099'], 2026));
        $this->assertSame('2026-09-001', DocumentNumberSeriesHelper::nextDcnNo([], 2026, 9));
        $this->assertSame('2026-09-047', DocumentNumberSeriesHelper::nextDcnNo(['2026-01-001', '2026-08-046'], 2026, 9));
    }

    public function test_members_in_series_ignore_other_leaves(): void
    {
        $rows = DocumentNumberSeriesHelper::membersInSeries([
            'CSPC-F-ACCTG-17B4',
            'CSPC-F-ACCTG-17B10',
            'CSPC-F-ACCTG-17C1',
            'CSPC-F-ACCTG-17B9',
        ], 'CSPC-F-ACCTG-17B', true);

        $numbers = array_column($rows, 'number');
        $this->assertSame([4, 9, 10], $numbers);
    }

    public function test_suggest_form_no_defaults_when_year_empty(): void
    {
        $data = DocumentNumberSeriesHelper::suggestFormNo(Request::create('/x', 'GET', [
            'kind' => 'drf',
        ]));

        $this->assertSame('drf', $data['kind']);
        $this->assertNotSame('', $data['suggested']);
        $this->assertSame((int) now('Asia/Manila')->year, $data['year']);
    }

    public function test_preview_shift_moves_only_later_b_numbers(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }
        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasTable('dcs_document_requests')
            || ! Schema::hasTable('dcs_doc_types')) {
            $this->markTestSkipped('DCS tables are not migrated.');
        }

        $account = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $userId = (int) (DB::table($account)->orderBy('id')->value('id') ?: 0);
        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 1);
        $typeId = (int) (DB::table('dcs_doc_types')->whereNull('parent_id')->orderBy('id')->value('id') ?: 0);
        if ($userId < 1 || $typeId < 1) {
            $this->markTestSkipped('DCS lookup rows are missing.');
        }

        $now = now();
        foreach (['CSPC-F-TEST-17B2', 'CSPC-F-TEST-17B4', 'CSPC-F-TEST-17B5', 'CSPC-F-TEST-17C1'] as $docNo) {
            $requestId = (int) DB::table('dcs_document_requests')->insertGetId(array_filter([
                'version_id' => $versionId,
                'doc_type_id' => $typeId,
                'sub_type_id' => null,
                'approval_status' => 'not_applicable',
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
                'is_draft' => Schema::hasColumn('dcs_document_requests', 'is_draft') ? false : null,
            ], fn ($v) => $v !== null));
            $ml = [
                'request_id' => $requestId,
                'doc_no' => $docNo,
                'doc_title' => $docNo,
                'revise_no' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
                $ml['revision_status'] = 'latest';
            }
            if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
                $ml['allows_revision'] = true;
            }
            if (Schema::hasColumn('dcs_masterlist_registration', 'doc_type_id')) {
                $ml['doc_type_id'] = $typeId;
            }
            DB::table('dcs_masterlist_registration')->insert($ml);
        }

        $preview = DocumentNumberSeriesHelper::previewInsertShift(Request::create('/x', 'GET', [
            'doc_no' => 'CSPC-F-TEST-17B4',
            'doc_type_id' => $typeId,
        ]));

        $this->assertTrue($preview['ok'] ?? false, $preview['error'] ?? 'preview failed');
        $from = array_column($preview['shifts'], 'from');
        $this->assertContains('CSPC-F-TEST-17B4', $from);
        $this->assertContains('CSPC-F-TEST-17B5', $from);
        $this->assertNotContains('CSPC-F-TEST-17B2', $from);
        $this->assertNotContains('CSPC-F-TEST-17C1', $from);
    }
}
