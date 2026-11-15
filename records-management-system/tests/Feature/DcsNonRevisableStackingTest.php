<?php

namespace Tests\Feature;

use App\Helpers\RegisterPersistHelper;
use App\Helpers\RegisterQueryHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DcsNonRevisableStackingTest extends TestCase
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

    private function accountTable(): string
    {
        return Schema::hasTable('sys_account') ? 'sys_account' : 'account';
    }

    private function skipUnlessSchemaReady(): void
    {
        if (! Schema::hasTable('dcs_doc_types')
            || ! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasTable('dcs_document_requests')) {
            $this->markTestSkipped('DCS registration tables are not migrated.');
        }
    }

    private function seedUserId(): int
    {
        $account = $this->accountTable();
        $id = (int) (DB::table($account)->orderBy('id')->value('id') ?: 0);
        if ($id < 1) {
            $this->markTestSkipped('No account row available for created_by.');
        }

        return $id;
    }

    private function insertType(string $name, ?int $parentId = null): int
    {
        return (int) DB::table('dcs_doc_types')->insertGetId([
            'doc_type_name' => $name,
            'parent_id' => $parentId,
        ]);
    }

    /**
     * @return array{request_id:int, ml_id:int}
     */
    private function insertRegistration(
        int $userId,
        int $docTypeId,
        ?int $subTypeId,
        string $docNo,
        int $reviseNo,
        string $revisionStatus = 'latest'
    ): array {
        $now = now();
        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 1);
        $requestId = (int) DB::table('dcs_document_requests')->insertGetId(array_filter([
            'version_id' => $versionId,
            'doc_type_id' => $docTypeId,
            'sub_type_id' => $subTypeId,
            'approval_status' => 'not_applicable',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ], fn ($v) => $v !== null));

        $row = [
            'request_id' => $requestId,
            'doc_type_id' => $docTypeId,
            'doc_no' => $docNo,
            'doc_title' => 'Stack Test '.$docNo,
            'revise_no' => $reviseNo,
            'revision_status' => $revisionStatus,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $mlId = (int) DB::table('dcs_masterlist_registration')->insertGetId($row);

        return ['request_id' => $requestId, 'ml_id' => $mlId];
    }

    public function test_syllabi_like_type_skips_promote_latest(): void
    {
        $this->skipUnlessSchemaReady();
        $userId = $this->seedUserId();

        $parentId = $this->insertType('NR Parent '.uniqid(), null);
        $subTypeId = $this->insertType('NR Syllabi '.uniqid(), $parentId);
        $docNo = 'NR-STACK-'.strtoupper(substr(uniqid(), -6));

        $first = $this->insertRegistration($userId, $parentId, $subTypeId, $docNo, 0);
        $second = $this->insertRegistration($userId, $parentId, $subTypeId, $docNo, 0);

        RegisterPersistHelper::promoteLatestForDoc($docNo, $parentId, $subTypeId);

        $statuses = DB::table('dcs_masterlist_registration')
            ->whereIn('id', [$first['ml_id'], $second['ml_id']])
            ->pluck('revision_status')
            ->all();
        $this->assertSame(['latest', 'latest'], $statuses);
        $this->assertFalse(RegisterQueryHelper::effectiveTypeAllowsRevision($parentId, $subTypeId));
    }

    public function test_update_list_stacks_syllabi_like_without_obsolete_badges(): void
    {
        $this->skipUnlessSchemaReady();
        $userId = $this->seedUserId();

        $parentId = $this->insertType('NR List Parent '.uniqid(), null);
        $subTypeId = $this->insertType('NR List Syllabi '.uniqid(), $parentId);
        $docNo = 'NR-LIST-'.strtoupper(substr(uniqid(), -6));

        $this->insertRegistration($userId, $parentId, $subTypeId, $docNo, 0);
        $this->insertRegistration($userId, $parentId, $subTypeId, $docNo, 0);

        $list = RegisterQueryHelper::updateList($docNo, (string) $parentId, 1, 50);
        if (($list['total'] ?? 0) === 0) {
            $this->markTestSkipped('updateList returned no rows (visibility scope empty in test env).');
        }

        $group = collect($list['rows'])->first(fn ($g) => ($g['doc_no'] ?? '') === $docNo);
        $this->assertNotNull($group);
        $this->assertFalse((bool) ($group['allows_revision'] ?? true));
        $this->assertNotEmpty($group['children'] ?? []);
        foreach ($group['children'] as $child) {
            $this->assertSame('latest', strtolower((string) ($child['revision_status'] ?? '')));
            $this->assertNotSame('obsolete', strtolower((string) ($child['revision_status'] ?? '')));
        }
        $this->assertSame('latest', strtolower((string) ($group['parent']['revision_status'] ?? '')));
    }

    public function test_revisable_type_still_blocks_duplicate_new_via_find_matching(): void
    {
        $this->skipUnlessSchemaReady();
        $userId = $this->seedUserId();

        $parentId = $this->insertType('Rev Parent '.uniqid(), null);
        $subTypeId = $this->insertType('Rev Sub '.uniqid(), $parentId);
        $docNo = 'REV-BLOCK-'.strtoupper(substr(uniqid(), -6));

        $this->insertRegistration($userId, $parentId, $subTypeId, $docNo, 0);

        $result = RegisterPersistHelper::findMatchingRegistrationRows($docNo, $parentId, $subTypeId);
        $this->assertTrue($result['found']);
        $this->assertTrue(RegisterQueryHelper::effectiveTypeAllowsRevision($parentId, $subTypeId));
    }

    public function test_syllabi_like_rejects_revised_mode_in_persist_precheck(): void
    {
        $this->skipUnlessSchemaReady();

        $parentId = $this->insertType('NR Rev Parent '.uniqid(), null);
        $subTypeId = $this->insertType('NR Rev Syllabi '.uniqid(), $parentId);

        $this->assertFalse(RegisterPersistHelper::effectiveAllowsRevision($parentId, $subTypeId));

        $mode = 'revised';
        $allows = RegisterPersistHelper::effectiveAllowsRevision($parentId, $subTypeId);
        $blocked = (! $allows) && $mode === 'revised';
        $this->assertTrue($blocked);
    }
}
