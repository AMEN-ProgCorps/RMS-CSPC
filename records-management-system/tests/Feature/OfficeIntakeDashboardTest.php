<?php

namespace Tests\Feature;

use App\Helpers\OfficeIntakeHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class OfficeIntakeDashboardTest extends TestCase
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

        if (! Schema::hasTable('dcs_office_intake_drf')
            || ! Schema::hasTable('dcs_office_intake_dcn')) {
            $this->markTestSkipped('Office intake tables are not migrated.');
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
            $this->insertRole($this->limitedRoleId, 'DCS Limited OFI Dash', [
                'can_access_dcs' => true,
                'dcs_can_office_intake' => true,
            ]);

            $this->limitedUserId = DB::table($account)->insertGetId([
                'username' => 'dcs_ofi_dash_'.$base,
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
                'last_name' => 'OfiDash',
                'email' => 'dcs_ofi_dash_'.$base.'@example.com',
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
            'dcs_can_office_intake',
        ] as $column) {
            if (! Schema::hasColumn($details, $column)) {
                unset($row[$column]);
            }
        }

        DB::table($details)->insert($row);
        DB::table($keys)->insert([
            'id' => $id,
            'key_name' => $name.' '.$id,
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

    public function test_attention_payload_shape_and_fresh_endpoint(): void
    {
        $user = User::find($this->limitedUserId);
        $this->actingAs($user);

        $payload = OfficeIntakeHelper::officeDashboardAttentionPayload();
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('total', $payload);
        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('incoming_total', $payload);
        $this->assertIsArray($payload['items']);

        $response = $this->getJson(route('dcs.api.office-intake.attention', ['fresh' => 1]));
        $response->assertOk();
        $response->assertJsonStructure(['total', 'items', 'incoming_total']);
    }

    public function test_pipeline_counts_use_aggregate_shape(): void
    {
        $this->actingAs(User::find($this->limitedUserId));

        foreach (['drf', 'dcn'] as $type) {
            $counts = OfficeIntakeHelper::officeIntakePipelineCounts($type);
            $this->assertArrayHasKey('total', $counts);
            $this->assertArrayHasKey('submitted', $counts);
            $this->assertArrayHasKey('correction', $counts);
            $this->assertArrayHasKey('print_ready', $counts);
            $this->assertArrayHasKey('printed', $counts);
            $this->assertArrayHasKey('registered', $counts);
        }
    }

    public function test_incoming_page_paginates(): void
    {
        $component = Livewire::actingAs(User::find($this->limitedUserId))
            ->test('pages.dcs.office.incoming');

        $component->assertOk();
        $component->assertSet('perPage', 25);
        $html = (string) $component->html();
        $this->assertTrue(
            str_contains($html, 'ofi-doc-empty')
            || str_contains($html, 'ofi-pagination')
            || str_contains($html, 'ofi-doc-row')
            || str_contains($html, 'Incoming'),
            'Incoming page should render list chrome'
        );
    }

    public function test_preview_buttons_exist_on_create_forms(): void
    {
        $user = User::find($this->limitedUserId);

        $drf = $this->actingAs($user)->get(route('dcs.office.drf.create'));
        $drf->assertOk();
        $drf->assertSee('Preview form', false);
        $drf->assertSee(route('dcs.office.drf.preview', absolute: false), false);

        $dcn = $this->actingAs($user)->get(route('dcs.office.dcn.create'));
        $dcn->assertOk();
        $dcn->assertSee('Preview form', false);
        $dcn->assertSee(route('dcs.office.dcn.preview', absolute: false), false);
        $dcn->assertSee('Also create a Document Request Form', false);
    }

    public function test_dcn_to_drf_handoff_banner_on_create(): void
    {
        if (! Schema::hasTable('dcs_office_intake_dcn')) {
            $this->markTestSkipped('DCN table missing');
        }

        $now = now();
        $row = [
            'document_no' => 'HANDOFF-'.uniqid(),
            'document_title' => 'Handoff title',
            'department_office_id' => $this->vpaaOfficeId,
            'created_by' => $this->limitedUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('dcs_office_intake_dcn', 'originator_name')) {
            $row['originator_name'] = 'Originator';
        }
        if (Schema::hasColumn('dcs_office_intake_dcn', 'expects_drf')) {
            $row['expects_drf'] = true;
        }
        if (Schema::hasColumn('dcs_office_intake_dcn', 'change_from')) {
            $row['change_from'] = 'Old';
        }
        if (Schema::hasColumn('dcs_office_intake_dcn', 'change_to')) {
            $row['change_to'] = 'New';
        }
        if (Schema::hasColumn('dcs_office_intake_dcn', 'justification')) {
            $row['justification'] = 'Test handoff';
        }

        $dcnId = (int) DB::table('dcs_office_intake_dcn')->insertGetId($row);

        $page = $this->actingAs(User::find($this->limitedUserId))
            ->get(route('dcs.office.drf.create', ['from_dcn' => $dcnId]));

        $page->assertOk();
        $page->assertSee('Continuing from DCN #'.$dcnId, false);
        $page->assertSee('Open saved DCN', false);
    }
}
