<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfficeDcnDocNoDrfTest extends TestCase
{
    use DatabaseTransactions;

    private int $limitedRoleId;

    private int $limitedUserId;

    private int $vpaaOfficeId;

    protected function setUp(): void
    {
        try {
            parent::setUp();
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database unavailable: '.$e->getMessage());
        }

        if (! Schema::hasTable('dcs_office_intake_dcn')
            || ! Schema::hasTable('dcs_masterlist_registration')) {
            $this->markTestSkipped('Office intake / masterlist tables are not migrated.');
        }

        $this->withHeader(
            'User-Agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $this->ensureDcsSubsystemActive();
        $this->ensureOffice('RFIO', 'Records and Freedom of Information Unit');
        $this->ensureOffice('VPAA', 'Vice President for Academic Affairs');
        $this->vpaaOfficeId = (int) DB::table($this->officeTable())->where('office_code', 'VPAA')->value('id');

        DB::transaction(function () {
            $details = $this->conditionTable();
            $keys = $this->keyTable();
            $account = $this->accountTable();
            $accountDetails = $this->detailsTable();

            $maxDetailsId = (int) (DB::table($details)->max('key_id') ?: 0);
            $maxKeyId = (int) (DB::table($keys)->max('id') ?: 0);
            $base = max($maxDetailsId, $maxKeyId) + 1;

            $this->limitedRoleId = $base;
            $this->insertRole($this->limitedRoleId, 'DCS Limited DCN DocNo', ['can_access_dcs' => true]);

            $this->limitedUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_dcn_docno_' . $base,
                'password' => bcrypt('password'),
                'account_status' => 1,
                'account_role' => $this->limitedRoleId,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);

            DB::table($accountDetails)->insert([
                'account_id' => $this->limitedUserId,
                'first_name' => 'Limited',
                'last_name' => 'DcnDoc',
                'email' => 'dcs_dcn_docno_' . $base . '@example.com',
                'office_id' => $this->vpaaOfficeId,
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

        foreach ([
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
            'dcs_view_all_documents',
        ] as $column) {
            if (! Schema::hasColumn($details, $column)) {
                unset($row[$column]);
            }
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

    private function ensureOffice(string $code, string $name): void
    {
        $tbl = $this->officeTable();
        if (! Schema::hasTable($tbl)) {
            return;
        }
        if (DB::table($tbl)->where('office_code', $code)->exists()) {
            return;
        }
        $row = [
            'office_code' => $code,
            'office_name' => $name,
        ];
        if (Schema::hasColumn($tbl, 'is_active')) {
            $row['is_active'] = true;
        }
        DB::table($tbl)->insert($row);
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

    /** @return array{doc_no: string, doc_title: string, request_id: int} */
    private function insertRevisableRegistration(string $suffix): array
    {
        $docTypeId = (int) (\App\Helpers\RegisterQueryHelper::parentTypeIdMap()['internal_docs'] ?? 0);
        if ($docTypeId < 1) {
            $docTypeId = (int) (DB::table('dcs_doc_types')
                ->whereNull('parent_id')
                ->whereRaw('LOWER(TRIM(doc_type_name)) = ?', ['internal'])
                ->value('id') ?: 0);
        }
        if ($docTypeId < 1) {
            $payload = ['doc_type_name' => 'Internal', 'parent_id' => null];
            if (Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
                $payload['allows_revision'] = true;
            }
            $docTypeId = (int) DB::table('dcs_doc_types')->insertGetId($payload);
        } elseif (Schema::hasColumn('dcs_doc_types', 'allows_revision')) {
            DB::table('dcs_doc_types')->where('id', $docTypeId)->update(['allows_revision' => true]);
        }

        $versionId = (int) (DB::table('dcs_version_type')->orderBy('id')->value('id') ?: 0);
        if ($versionId < 1) {
            $this->markTestSkipped('No dcs_version_type row available.');
        }

        $now = now();
        $req = [
            'version_id' => $versionId,
            'doc_type_id' => $docTypeId,
            'sub_type_id' => null,
            'approval_status' => 'not_applicable',
            'created_by' => $this->limitedUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_document_requests', 'is_draft')) {
            $req['is_draft'] = false;
        }
        $requestId = (int) DB::table('dcs_document_requests')->insertGetId($req);

        $docNo = 'DCN-REV-' . $suffix;
        $docTitle = 'Revisable Doc ' . $suffix;
        $ml = [
            'request_id' => $requestId,
            'doc_type_id' => $docTypeId,
            'doc_no' => $docNo,
            'doc_title' => $docTitle,
            'revise_no' => 0,
            'created_by' => $this->limitedUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_masterlist_registration', 'revision_status')) {
            $ml['revision_status'] = 'latest';
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'allows_revision')) {
            $ml['allows_revision'] = true;
        }
        DB::table('dcs_masterlist_registration')->insert($ml);

        return [
            'doc_no' => $docNo,
            'doc_title' => $docTitle,
            'request_id' => $requestId,
        ];
    }

    /** @return array<string, mixed> */
    private function validDcnPayload(string $docNo, string $docTitle, bool $alsoCreateDrf = false): array
    {
        $payload = [
            'documentNo' => $docNo,
            'documentTitle' => $docTitle,
            'changeFrom' => 'Old wording',
            'changeTo' => 'New wording',
            'dcnJustification' => 'Needed for process update',
            'originatorName' => 'Originator Test',
            'departmentOfficeId' => $this->vpaaOfficeId,
            'departmentDate' => now()->toDateString(),
            'reviewedByName' => ['Reviewer One'],
            'reviewedByOn' => [now()->toDateString()],
            'confirmDataCorrect' => '1',
        ];
        if ($alsoCreateDrf) {
            $payload['alsoCreateDrf'] = '1';
        }

        return $payload;
    }

    public function test_store_dcn_rejects_unknown_document_no(): void
    {
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->from(route('dcs.office.dcn.create'))
            ->post(route('dcs.office.dcn.store'), $this->validDcnPayload(
                'UNKNOWN-DOC-NO-' . uniqid(),
                'Some Title'
            ));

        $response->assertRedirect(route('dcs.office.dcn.create'));
        $response->assertSessionHasErrors('documentNo');
        $this->assertStringContainsString(
            'is not a registered',
            strtolower((string) session('errors')->first('documentNo'))
        );
    }

    public function test_store_dcn_accepts_latest_revisable_document_no(): void
    {
        $doc = $this->insertRevisableRegistration(uniqid());

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->post(route('dcs.office.dcn.store'), $this->validDcnPayload(
                $doc['doc_no'],
                $doc['doc_title']
            ));

        $response->assertRedirect();
        $this->assertTrue(
            str_contains($response->headers->get('Location') ?? '', '/dcs/office/dcn/'),
            'Expected redirect to DCN show'
        );

        $this->assertDatabaseHas('dcs_office_intake_dcn', [
            'document_no' => $doc['doc_no'],
            'document_title' => $doc['doc_title'],
            'created_by' => $this->limitedUserId,
        ]);

        $show = $this->actingAs(User::find($this->limitedUserId))
            ->get($response->headers->get('Location') ?? '');
        $show->assertOk();
        $show->assertSee('Create DRF', false);
        $show->assertDontSee('View linked DRF', false);
    }

    public function test_limited_user_can_search_revisable_documents_campus_wide(): void
    {
        $doc = $this->insertRevisableRegistration(uniqid());

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->getJson('/dcs/api/office/revisable-documents?q=' . urlencode(substr($doc['doc_no'], 0, 8)));

        $response->assertOk();
        $rows = $response->json();
        $this->assertIsArray($rows);
        $this->assertTrue(
            collect($rows)->contains(fn ($row) => ($row['doc_no'] ?? '') === $doc['doc_no']),
            'Expected campus-wide revisable document in search results'
        );
    }

    public function test_also_create_drf_redirects_to_prefilled_drf_create(): void
    {
        $doc = $this->insertRevisableRegistration(uniqid());

        $response = $this->actingAs(User::find($this->limitedUserId))
            ->post(route('dcs.office.dcn.store'), $this->validDcnPayload(
                $doc['doc_no'],
                $doc['doc_title'],
                true
            ));

        $response->assertRedirect();
        $location = $response->headers->get('Location') ?? '';
        $this->assertStringContainsString('/dcs/office/drf/create', $location);
        $this->assertStringContainsString('from_dcn=', $location);

        $follow = $this->actingAs(User::find($this->limitedUserId))
            ->get($location);

        $follow->assertOk();
        $follow->assertSee($doc['doc_title'], false);
        $follow->assertSee('Originator Test', false);
        $follow->assertDontSee('Needed for process update', false);
        $follow->assertDontSee('Change from:', false);
    }

    public function test_cannot_create_second_drf_from_same_dcn(): void
    {
        if (! Schema::hasColumn('dcs_office_intake_drf', 'source_office_dcn_id')) {
            $this->markTestSkipped('source_office_dcn_id is not migrated.');
        }

        $doc = $this->insertRevisableRegistration(uniqid());

        $create = $this->actingAs(User::find($this->limitedUserId))
            ->post(route('dcs.office.dcn.store'), $this->validDcnPayload(
                $doc['doc_no'],
                $doc['doc_title'],
                true
            ));
        $create->assertRedirect();
        $location = $create->headers->get('Location') ?? '';
        $this->assertMatchesRegularExpression('/from_dcn=(\d+)/', $location);
        preg_match('/from_dcn=(\d+)/', $location, $m);
        $dcnId = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $dcnId);

        $now = now();
        $drfPayload = [
            'drf_no' => null,
            'drf_date' => $now->toDateString(),
            'doc_title' => $doc['doc_title'],
            'created_by' => $this->limitedUserId,
            'created_at' => $now,
            'updated_at' => $now,
            'source_office_dcn_id' => $dcnId,
        ];
        $drfId = (int) DB::table('dcs_office_intake_drf')->insertGetId($drfPayload);

        $this->assertTrue(\App\Helpers\OfficeIntakeHelper::officeDcnHasLinkedDrf($dcnId));
        $this->assertSame($drfId, \App\Helpers\OfficeIntakeHelper::findLinkedDrfIdForOfficeDcn($dcnId));

        $again = $this->actingAs(User::find($this->limitedUserId))
            ->get(route('dcs.office.drf.create', ['from_dcn' => $dcnId]));
        $again->assertRedirect();
        $this->assertStringContainsString('/dcs/office/drf/' . $drfId, $again->headers->get('Location') ?? '');
    }

    /** @return array<string, mixed> */
    private function validDrfPayload(string $title): array
    {
        return [
            'drfDate' => now()->toDateString(),
            'drfTitle' => $title,
            'originatorName' => 'DRF Originator',
            'docTypeKind' => 'internal',
            'descriptionReason' => 'Office intake DRF for schema split test.',
            'distributeToOffice' => [$this->vpaaOfficeId],
            'preparedByName' => 'Prepared Name',
            'preparedByDesignation' => 'Prepared Designation',
            'reviewedByName' => 'Reviewed Name',
            'reviewedByDesignation' => 'Reviewed Designation',
            'approvedByName' => 'Approved Name',
            'approvedByDesignation' => 'Approved Designation',
            'confirmDataCorrect' => '1',
        ];
    }

    public function test_store_drf_and_print_use_office_intake_table(): void
    {
        if (! Schema::hasTable('dcs_office_intake_drf')) {
            $this->markTestSkipped('Office intake DRF table is not migrated.');
        }

        $title = 'Office DRF Print ' . uniqid();
        $response = $this->actingAs(User::find($this->limitedUserId))
            ->post(route('dcs.office.drf.store'), $this->validDrfPayload($title));

        $response->assertRedirect();
        $location = $response->headers->get('Location') ?? '';
        $this->assertMatchesRegularExpression('#/dcs/office/drf/(\d+)#', $location);
        preg_match('#/dcs/office/drf/(\d+)#', $location, $m);
        $drfId = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $drfId);

        $this->assertDatabaseHas('dcs_office_intake_drf', [
            'id' => $drfId,
            'doc_title' => $title,
            'created_by' => $this->limitedUserId,
        ]);
        $this->assertFalse(
            Schema::hasColumn('dcs_document_request_form', 'is_office_intake'),
            'Register DRF must not keep is_office_intake after the split.'
        );
        $this->assertFalse(
            DB::table('dcs_document_request_form')->where('id', $drfId)->where('doc_title', $title)->exists()
        );

        $print = $this->actingAs(User::find($this->limitedUserId))
            ->get(route('dcs.office.drf.print', $drfId));
        $print->assertOk();
        $print->assertSee($title, false);
    }

    public function test_register_from_intake_links_office_row_only(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')
            || ! Schema::hasColumn('dcs_office_intake_dcn', 'registered_request_id')) {
            $this->markTestSkipped('Office intake registered_request_id is not migrated.');
        }

        $doc = $this->insertRevisableRegistration(uniqid());
        $create = $this->actingAs(User::find($this->limitedUserId))
            ->post(route('dcs.office.dcn.store'), $this->validDcnPayload(
                $doc['doc_no'],
                $doc['doc_title']
            ));
        $create->assertRedirect();
        $location = $create->headers->get('Location') ?? '';
        $this->assertMatchesRegularExpression('#/dcs/office/dcn/(\d+)#', $location);
        preg_match('#/dcs/office/dcn/(\d+)#', $location, $m);
        $intakeId = (int) ($m[1] ?? 0);
        $this->assertGreaterThan(0, $intakeId);

        $registered = $this->insertRevisableRegistration(uniqid());
        $now = now();
        DB::table('dcs_document_change_notice')->insert([
            'request_id' => $registered['request_id'],
            'dcn_no' => 'REG-DCN-' . $registered['request_id'],
            'dcn_date' => $now->toDateString(),
            'brief_purpose' => 'Register copy after RFIO intake',
            'created_by' => $this->limitedUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        \App\Helpers\OfficeIntakeHelper::markIntakeRegistered(
            'dcn',
            $intakeId,
            $registered['request_id'],
            $registered['doc_no'],
            $registered['doc_title'],
            false
        );

        $this->assertDatabaseHas('dcs_office_intake_dcn', [
            'id' => $intakeId,
            'registered_request_id' => $registered['request_id'],
        ]);
        $this->assertDatabaseHas('dcs_document_change_notice', [
            'request_id' => $registered['request_id'],
            'dcn_no' => 'REG-DCN-' . $registered['request_id'],
        ]);
        $this->assertFalse(Schema::hasColumn('dcs_document_change_notice', 'is_office_intake'));
        $this->assertFalse(Schema::hasColumn('dcs_document_change_notice', 'registered_request_id'));
    }
}