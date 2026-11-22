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

    public function test_preview_lists_only_exact_number_holders(): void
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
        $seed = function (string $docNo, string $title) use ($userId, $versionId, $typeId, $now): int {
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
                'doc_title' => $title,
                'revise_no' => 0,
                'created_by' => $userId,
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

            return $requestId;
        };

        $seed('CSPC-F-EXACT-01', 'TEST 2');
        $seed('CSPC-F-EXACT-01', 'TESTRING');
        $seed('CSPC-F-EXACT-1024', 'sample0ct24');
        $seed('CSPC-F-EXACT-1025', 'sampleform1018');

        $preview = DocumentNumberSeriesHelper::previewInsertShift(Request::create('/x', 'GET', [
            'doc_no' => 'CSPC-F-EXACT-01',
            'doc_type_id' => $typeId,
        ]));

        $this->assertTrue($preview['ok'] ?? false, $preview['error'] ?? 'preview failed');
        $this->assertSame('exact_select', $preview['mode'] ?? null);
        $from = array_column($preview['candidates'] ?? $preview['shifts'], 'from');
        $titles = array_column($preview['candidates'] ?? $preview['shifts'], 'title');
        $this->assertContains('CSPC-F-EXACT-01', $from);
        $this->assertNotContains('CSPC-F-EXACT-1024', $from);
        $this->assertNotContains('CSPC-F-EXACT-1025', $from);
        $this->assertContains('TEST 2', $titles);
        $this->assertContains('TESTRING', $titles);
        $this->assertCount(2, $from);
    }

    public function test_insert_shift_renumbers_only_selected_exact_holders(): void
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
        $seed = function (string $docNo, string $title, bool $allowsRevision = true) use ($userId, $versionId, $typeId, $now): int {
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
                'doc_title' => $title,
                'revise_no' => 0,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
                $ml['revision_status'] = 'latest';
            }
            if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
                $ml['allows_revision'] = $allowsRevision;
            }
            if (Schema::hasColumn('dcs_masterlist_registration', 'doc_type_id')) {
                $ml['doc_type_id'] = $typeId;
            }
            DB::table('dcs_masterlist_registration')->insert($ml);

            return $requestId;
        };

        $test2 = $seed('CSPC-F-SHIFT-01', 'TEST 2');
        $testring = $seed('CSPC-F-SHIFT-01', 'TESTRING');
        $high1024 = $seed('CSPC-F-SHIFT-1024', 'sample0ct24');
        $high1025 = $seed('CSPC-F-SHIFT-1025', 'sampleform1018');

        $result = DocumentNumberSeriesHelper::applyInsertShift(Request::create('/x', 'POST', [
            'doc_no' => 'CSPC-F-SHIFT-01',
            'doc_type_id' => $typeId,
            'insert_shift_confirmed' => '1',
            'insert_shift_request_ids' => (string) $test2,
        ]));

        $this->assertTrue($result['ok'] ?? false, $result['error'] ?? 'applyInsertShift failed');

        $docNoFor = function (int $requestId): string {
            return trim((string) DB::table('dcs_masterlist_registration')
                ->where('request_id', $requestId)
                ->value('doc_no'));
        };

        // Next free after 01 is 02 — not after series max (1026).
        $this->assertSame('CSPC-F-SHIFT-02', $docNoFor($test2));
        $this->assertSame('CSPC-F-SHIFT-01', $docNoFor($testring));
        $this->assertSame('CSPC-F-SHIFT-1024', $docNoFor($high1024));
        $this->assertSame('CSPC-F-SHIFT-1025', $docNoFor($high1025));
    }

    public function test_allocate_next_free_follows_sequence_not_series_max(): void
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
        foreach (['CSPC-F-SEQ-01', 'CSPC-F-SEQ-1024', 'CSPC-F-SEQ-1025'] as $docNo) {
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
                'created_by' => $userId,
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

        $targets = DocumentNumberSeriesHelper::allocateNextFreeNumbers(
            'CSPC-F-SEQ-01',
            $typeId,
            null,
            0,
            2
        );

        $this->assertSame(['CSPC-F-SEQ-02', 'CSPC-F-SEQ-03'], $targets);
        $this->assertSame(
            'CSPC-F-SEQ-02',
            DocumentNumberSeriesHelper::nextInSeries('CSPC-F-SEQ-01', $typeId, null, 0)
        );
    }

    public function test_preview_candidates_start_unchecked(): void
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
            'doc_no' => 'CSPC-F-UNCHK-01',
            'doc_title' => 'Unchecked by default',
            'revise_no' => 0,
            'created_by' => $userId,
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

        $preview = DocumentNumberSeriesHelper::previewInsertShift(Request::create('/x', 'GET', [
            'doc_no' => 'CSPC-F-UNCHK-01',
            'doc_type_id' => $typeId,
            'effectivity_date' => now()->toDateString(),
        ]));

        $this->assertTrue($preview['ok'] ?? false, $preview['error'] ?? 'preview failed');
        $this->assertNull($preview['date_warning'] ?? 'x');
        foreach ($preview['candidates'] ?? [] as $row) {
            $this->assertFalse($row['selected'] ?? true);
        }
    }

    public function test_insert_shift_requires_selection(): void
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
            'doc_no' => 'CSPC-F-NEEDSEL-01',
            'doc_title' => 'Need selection',
            'revise_no' => 0,
            'created_by' => $userId,
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

        $result = DocumentNumberSeriesHelper::applyInsertShift(Request::create('/x', 'POST', [
            'doc_no' => 'CSPC-F-NEEDSEL-01',
            'doc_type_id' => $typeId,
            'insert_shift_confirmed' => '1',
        ]));

        $this->assertFalse($result['ok'] ?? true);
        $this->assertStringContainsString('Select at least one', (string) ($result['error'] ?? ''));
    }
}
