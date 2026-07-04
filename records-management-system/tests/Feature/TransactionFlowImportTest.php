<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransactionFlowImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Authenticate admin user (ID 1)
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        // Clean up any existing test records to avoid conflicts
        DB::table('office')->whereIn('office_code', ['TEST-OFF1', 'TEST-OFF2', 'TEST-OFF3', 'TEST-OFF4', 'TEST-OFF5'])->delete();
        DB::table('dts_transaction_flow')->whereIn('flow_code', ['TEST-FLOW-IMP1', 'TEST-FLOW-IMP2', 'TEST-FLOW-IMP-DUP', 'NEW-PREDEF-CF-FLOW'])->delete();
        DB::table('dts_copy_filled_transaction')->where('control_num', 'NEW-PREDEF-CF-FLOW')->delete();
        
        // Ensure ORIGIN exists
        DB::table('office')->updateOrInsert(
            ['office_code' => 'ORIGIN'],
            ['office_name' => 'Originated Office', 'is_active' => true]
        );

        // Seed test offices
        DB::table('office')->insert([
            ['office_code' => 'TEST-OFF1', 'office_name' => 'Test Office One', 'is_active' => true],
            ['office_code' => 'TEST-OFF2', 'office_name' => 'Test Office Two', 'is_active' => true],
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('office')->whereIn('office_code', ['TEST-OFF1', 'TEST-OFF2', 'TEST-OFF3', 'TEST-OFF4', 'TEST-OFF5'])->delete();
        DB::table('dts_transaction_flow')->whereIn('flow_code', ['TEST-FLOW-IMP1', 'TEST-FLOW-IMP2', 'TEST-FLOW-IMP-DUP', 'NEW-PREDEF-CF-FLOW'])->delete();
        DB::table('dts_copy_filled_transaction')->where('control_num', 'NEW-PREDEF-CF-FLOW')->delete();
        parent::tearDown();
    }

    public function test_successful_multiple_flows_import_with_last_stop_origin()
    {
        // Flow 1 does not end with [], Flow 2 does
        $fileContent = "=Test Imported Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1->TEST-OFF2;\n=Test Imported Flow Two;\nTEST-FLOW-IMP2;\n[]->TEST-OFF2->[];\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSet('selectedPredefined', '')
            ->assertSet('flowName', '')
            ->assertSet('flowCode', '')
            ->assertSet('flowOffices', ['ORIGIN', 'ORIGIN'])
            ->assertSee("Successfully imported 2 predefined flow(s) from the file!");

        // Assert they exist in the DB
        $this->assertDatabaseHas('dts_transaction_flow', ['flow_code' => 'TEST-FLOW-IMP1', 'flow_name' => 'Test Imported Flow One']);
        $this->assertDatabaseHas('dts_transaction_flow', ['flow_code' => 'TEST-FLOW-IMP2', 'flow_name' => 'Test Imported Flow Two']);

        // Assert sequences are saved and both end with ORIGIN
        $flow1 = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-IMP1')->first();
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow1->id, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN']);
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow1->id, 'sequence_ranking' => 2, 'office_code' => 'TEST-OFF1']);
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow1->id, 'sequence_ranking' => 3, 'office_code' => 'TEST-OFF2']);
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow1->id, 'sequence_ranking' => 4, 'office_code' => 'ORIGIN']); // Auto-appended

        $flow2 = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-IMP2')->first();
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow2->id, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN']);
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow2->id, 'sequence_ranking' => 2, 'office_code' => 'TEST-OFF2']);
        $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow2->id, 'sequence_ranking' => 3, 'office_code' => 'ORIGIN']);
    }

    public function test_import_fails_on_missing_semicolon()
    {
        $fileContent = "=Test Imported Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1->TEST-OFF2;\n=Test Imported Flow Two;\nTEST-FLOW-IMP2\n[]->TEST-OFF2;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertSet('errorMessage', 'Extraction failed: Line 5 ("TEST-FLOW-IMP2") must end with a semicolon \';\'.');
    }

    public function test_flow_import_fails_on_missing_equals_prefix()
    {
        $fileContent = "Test Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertSet('errorMessage', 'Extraction failed: Line 1 ("Test Flow One;") must start with \'=\' to indicate the start of a flow name.');
    }

    public function test_import_fails_on_invalid_office()
    {
        $fileContent = "=Test Imported Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n=Test Imported Flow Two;\nTEST-FLOW-IMP2;\n[]->TEST-OFF3;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertSet('errorMessage', 'Extraction failed: Line 6 ("[]->TEST-OFF3;"): office \'TEST-OFF3\' does not exist in the database or is typoed.');
    }

    public function test_import_skips_perfect_db_duplicate()
    {
        $existingId = DB::table('dts_transaction_flow')->max('id') + 1;
        DB::table('dts_transaction_flow')->insert([
            'id' => $existingId,
            'flow_name' => 'Existing Flow',
            'flow_code' => 'TEST-FLOW-IMP-DUP',
            'is_active' => true,
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'none',
        ]);

        $fileContent = "=Test Imported Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n=Existing Flow;\nTEST-FLOW-IMP-DUP;\n[]->TEST-OFF2;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSee("Successfully imported 1 predefined flow(s) from the file!");

        $this->assertDatabaseHas('dts_transaction_flow', ['flow_code' => 'TEST-FLOW-IMP1', 'flow_name' => 'Test Imported Flow One']);
        
        DB::table('dts_transaction_flow')->where('id', $existingId)->delete();
    }

    public function test_import_fails_on_mismatch_db_duplicate()
    {
        $existingId = DB::table('dts_transaction_flow')->max('id') + 1;
        DB::table('dts_transaction_flow')->insert([
            'id' => $existingId,
            'flow_name' => 'Existing Flow',
            'flow_code' => 'TEST-FLOW-IMP-DUP',
            'is_active' => true,
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'none',
        ]);

        $fileContent = "=Test Imported Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n=Different Name Flow;\nTEST-FLOW-IMP-DUP;\n[]->TEST-OFF2;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertSet('errorMessage', 'Extraction failed: Line 5 ("TEST-FLOW-IMP-DUP;"): Conflict detected. The flow code \'TEST-FLOW-IMP-DUP\' already exists in the database with a different name (\'Existing Flow\').');

        DB::table('dts_transaction_flow')->where('id', $existingId)->delete();
    }

    public function test_import_skips_perfect_file_level_duplicate()
    {
        $fileContent = "=Test Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n=Test Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSee("Successfully imported 1 predefined flow(s) from the file!");
    }

    public function test_import_fails_on_mismatch_file_level_duplicate()
    {
        $fileContent = "=Test Flow One;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1;\n=Different Flow;\nTEST-FLOW-IMP1;\n[]->TEST-OFF2;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertSet('errorMessage', 'Extraction failed: Line 5 ("TEST-FLOW-IMP1;"): Duplicate flow code \'TEST-FLOW-IMP1\' found within the uploaded file with a different name.');
    }

    public function test_line_numbers_reported_correctly_with_blank_lines()
    {
        $fileContent = "\n=Test Flow One;\n\nTEST-FLOW-IMP1;\n[]->TEST-OFF3;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertSet('errorMessage', 'Extraction failed: Line 5 ("[]->TEST-OFF3;"): office \'TEST-OFF3\' does not exist in the database or is typoed.');
    }

    public function test_ui_predefined_flow_initialization_and_smart_appending()
    {
        Volt::test('pages.admin.dts.transaction-flows')
            // 1. Initialization
            ->set('selectedPredefined', 'new')
            ->assertSet('flowOffices', ['ORIGIN', 'ORIGIN'])
            
            // 2. Smart Appending - insert before final ORIGIN
            ->set('selectedOffice', 'TEST-OFF1')
            ->call('addOfficeToPath')
            ->assertSet('flowOffices', ['ORIGIN', 'TEST-OFF1', 'ORIGIN'])

            // 3. Smart Appending 2 - insert before final ORIGIN
            ->set('selectedOffice', 'TEST-OFF2')
            ->call('addOfficeToPath')
            ->assertSet('flowOffices', ['ORIGIN', 'TEST-OFF1', 'TEST-OFF2', 'ORIGIN'])

            // 4. Remove last ORIGIN step manually
            ->call('removeOffice', 3)
            ->assertSet('flowOffices', ['ORIGIN', 'TEST-OFF1', 'TEST-OFF2'])

            // 5. Append test office normally (since final step is not ORIGIN anymore)
            ->set('selectedOffice', 'TEST-OFF1')
            ->call('addOfficeToPath')
            ->assertSet('flowOffices', ['ORIGIN', 'TEST-OFF1', 'TEST-OFF2', 'TEST-OFF1']);
    }

    public function test_import_with_optional_copy_furnished()
    {
        // Flow 1 has no copy furnished (3 lines), Flow 2 has copy furnished (4 lines)
        $fileContent = "=Test Flow No CF;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1->TEST-OFF2;\n=Test Flow With CF;\nTEST-FLOW-IMP2;\n[]->TEST-OFF2->[];\nTEST-OFF1, TEST-OFF2;\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSee("Successfully imported 2 predefined flow(s) from the file!");

        // Assert Flow 2 copy furnished database records exist
        $predefinedCF = DB::table('dts_copy_filled_transaction')
            ->where('control_num', 'TEST-FLOW-IMP2')
            ->first();

        $this->assertNotNull($predefinedCF);
        $this->assertEquals(2, $predefinedCF->total_office);

        $cfOffices = DB::table('dts_copy_filled_to_office')
            ->where('control_id', $predefinedCF->assign_offices_id)
            ->pluck('office_code')
            ->toArray();

        $this->assertEquals(['TEST-OFF1', 'TEST-OFF2'], $cfOffices);

        // Assert Flow 1 has no copy furnished record
        $this->assertDatabaseMissing('dts_copy_filled_transaction', ['control_num' => 'TEST-FLOW-IMP1']);
    }

    public function test_creation_pages_auto_load_predefined_copy_furnished()
    {
        // 1. Clean up potential leftover records first to avoid unique key constraints
        $assignOfficesId = 99999;
        DB::table('dts_copy_filled_to_office')->where('control_id', $assignOfficesId)->delete();
        DB::table('dts_copy_filled_transaction')->where('assign_offices_id', $assignOfficesId)->delete();
        DB::table('dts_copy_filled_transaction')->where('control_num', 'TEST-FLOW-CF-LOAD')->delete();
        DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-CF-LOAD')->delete();
        DB::table('dts_transaction_flow')->where('id', 99999)->delete();
        DB::table('dts_sequence_list')->where('control_id', 99999)->delete();

        // 2. Manually insert predefined copy furnished config for a flow code
        DB::table('dts_copy_filled_transaction')->insert([
            'control_num' => 'TEST-FLOW-CF-LOAD',
            'total_office' => 2,
            'assign_offices_id' => $assignOfficesId,
            'data_created' => now(),
            'date_modified' => now(),
        ]);
        DB::table('dts_copy_filled_to_office')->insert([
            ['control_id' => $assignOfficesId, 'office_code' => 'TEST-OFF1'],
            ['control_id' => $assignOfficesId, 'office_code' => 'TEST-OFF2'],
        ]);

        DB::table('dts_transaction_flow')->insert([
            'id' => 99999,
            'flow_name' => 'Load Test Flow',
            'flow_code' => 'TEST-FLOW-CF-LOAD',
            'is_active' => true,
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'none',
        ]);
        DB::table('dts_sequence_list')->insert([
            ['control_id' => 99999, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN'],
            ['control_id' => 99999, 'sequence_ranking' => 2, 'office_code' => 'ORIGIN'],
        ]);

        // 3. Test auto-loading copy furnished list in creation component
        Volt::test('pages.dts.create.internal')
            ->set('transaction_flow', 'TEST-FLOW-CF-LOAD')
            ->assertSet('copy_furnished', 'Yes')
            ->assertSet('cf_selected_offices', ['TEST-OFF1', 'TEST-OFF2']);

        // Clean up
        DB::table('dts_copy_filled_to_office')->where('control_id', $assignOfficesId)->delete();
        DB::table('dts_copy_filled_transaction')->where('assign_offices_id', $assignOfficesId)->delete();
        DB::table('dts_transaction_flow')->where('id', 99999)->delete();
        DB::table('dts_sequence_list')->where('control_id', 99999)->delete();
    }

    public function test_import_ignores_comment_lines()
    {
        // Incorporate lines starting with # which should be completely skipped
        $fileContent = "# This is a comment at the top\n=Test Flow No CF;\nTEST-FLOW-IMP1;\n[]->TEST-OFF1->TEST-OFF2;\n# Another comment in the middle\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSee("Successfully imported 1 predefined flow(s) from the file!");

        $this->assertDatabaseHas('dts_transaction_flow', ['flow_code' => 'TEST-FLOW-IMP1', 'flow_name' => 'Test Flow No CF']);
    }

    public function test_predefined_flow_selection_and_creation_views()
    {
        // 1. Insert temporary predefined flow for selection testing
        DB::table('dts_transaction_flow')->insert([
            'id' => 88888,
            'flow_name' => 'Select Path Flow',
            'flow_code' => 'TEST-FLOW-SELECT',
            'is_active' => true,
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'none',
        ]);
        DB::table('dts_sequence_list')->insert([
            ['control_id' => 88888, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN'],
            ['control_id' => 88888, 'sequence_ranking' => 2, 'office_code' => 'ORIGIN'],
        ]);

        Volt::test('pages.admin.dts.transaction-flows')
            // Test search filter
            ->set('searchPredefined', 'Select Path')
            // Test selectFlow trigger
            ->call('selectFlow', 88888)
            ->assertSet('selectedPredefined', '88888')
            ->assertSet('flowName', 'Select Path Flow')
            ->assertSet('flowCode', 'TEST-FLOW-SELECT')
            // Test startCreate trigger
            ->call('startCreate')
            ->assertSet('selectedPredefined', 'new')
            ->assertSet('flowName', '')
            ->assertSet('flowCode', '')
            // Test startImport trigger
            ->call('startImport')
            ->assertSet('selectedPredefined', 'import');

        // Clean up
        DB::table('dts_transaction_flow')->where('id', 88888)->delete();
        DB::table('dts_sequence_list')->where('control_id', 88888)->delete();
    }

    public function test_save_predefined_flow_with_copy_furnished()
    {
        // Insert temporary copy furnished offices
        DB::table('office')->insert([
            ['office_code' => 'TEST-OFF3', 'office_name' => 'Test Office Three', 'is_active' => true],
            ['office_code' => 'TEST-OFF4', 'office_name' => 'Test Office Four', 'is_active' => true],
            ['office_code' => 'TEST-OFF5', 'office_name' => 'Test Office Five', 'is_active' => true],
        ]);

        try {
            // 1. Create a predefined flow using the component
            Volt::test('pages.admin.dts.transaction-flows')
                ->set('selectedPredefined', 'new')
                ->set('flowName', 'New Custom Predefined Flow')
                ->set('flowCode', 'NEW-PREDEF-CF-FLOW')
                ->set('flowOffices', ['ORIGIN', 'TEST-OFF1', 'TEST-OFF2'])
                ->set('cfOffices', ['TEST-OFF3', 'TEST-OFF4'])
                ->call('savePredefinedFlow')
                ->assertHasNoErrors()
                ->assertSet('successMessage', 'Predefined flow created successfully!');

            $flow = DB::table('dts_transaction_flow')->where('flow_code', 'NEW-PREDEF-CF-FLOW')->first();
            $this->assertNotNull($flow);

            // Verify sequence list
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow->id, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN']);
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow->id, 'sequence_ranking' => 2, 'office_code' => 'TEST-OFF1']);
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow->id, 'sequence_ranking' => 3, 'office_code' => 'TEST-OFF2']);

            // Verify copy furnished
            $cfTx = DB::table('dts_copy_filled_transaction')->where('control_num', 'NEW-PREDEF-CF-FLOW')->first();
            $this->assertNotNull($cfTx);
            $this->assertDatabaseHas('dts_copy_filled_to_office', ['control_id' => $cfTx->assign_offices_id, 'office_code' => 'TEST-OFF3']);
            $this->assertDatabaseHas('dts_copy_filled_to_office', ['control_id' => $cfTx->assign_offices_id, 'office_code' => 'TEST-OFF4']);

            // 2. Update it and change copy-furnished list
            Volt::test('pages.admin.dts.transaction-flows')
                ->call('selectFlow', $flow->id)
                ->assertSet('flowName', 'New Custom Predefined Flow')
                ->assertSet('cfOffices', ['TEST-OFF3', 'TEST-OFF4'])
                // Modify cfOffices
                ->set('cfOffices', ['TEST-OFF5'])
                ->call('savePredefinedFlow')
                ->assertHasNoErrors();

            // Verify update
            $cfTxNew = DB::table('dts_copy_filled_transaction')->where('control_num', 'NEW-PREDEF-CF-FLOW')->first();
            $this->assertNotNull($cfTxNew);
            $this->assertDatabaseHas('dts_copy_filled_to_office', ['control_id' => $cfTxNew->assign_offices_id, 'office_code' => 'TEST-OFF5']);
            $this->assertDatabaseMissing('dts_copy_filled_to_office', ['control_id' => $cfTxNew->assign_offices_id, 'office_code' => 'TEST-OFF3']);

            // Clean up flow tables
            DB::table('dts_transaction_flow')->where('flow_code', 'NEW-PREDEF-CF-FLOW')->delete();
            DB::table('dts_sequence_list')->where('control_id', $flow->id)->delete();
            DB::table('dts_copy_filled_to_office')->where('control_id', $cfTxNew->assign_offices_id)->delete();
            DB::table('dts_copy_filled_transaction')->where('control_num', 'NEW-PREDEF-CF-FLOW')->delete();
        } finally {
            DB::table('office')->whereIn('office_code', ['TEST-OFF3', 'TEST-OFF4', 'TEST-OFF5'])->delete();
        }
    }

    public function test_predefined_flow_import_and_resolution_with_cluster_head()
    {
        // 1. Seed cluster and offices
        DB::table('office')->insert([
            ['office_code' => 'TEST-CH1', 'office_name' => 'Test Cluster Head Office', 'is_active' => true],
            ['office_code' => 'TEST-ORG1', 'office_name' => 'Test Originating Office', 'is_active' => true],
        ]);

        DB::table('cluster')->insert([
            'cluster_code' => 'TEST-CLUST1',
            'cluster_name' => 'Test Cluster One',
            'cluster_head' => 'TEST-CH1',
            'is_active' => true,
        ]);

        DB::table('office')->where('office_code', 'TEST-ORG1')->update(['cluster' => 'TEST-CLUST1']);

        try {
            // 2. Import flow containing [H]
            $fileContent = "=Test Cluster Flow;\nTEST-FLOW-CLUST;\n[]->[H]->TEST-OFF1;\n";
            $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

            Volt::test('pages.admin.dts.transaction-flows')
                ->set('selectedPredefined', 'import')
                ->set('flowFile', $file)
                ->call('importFlow')
                ->assertHasNoErrors();

            $flow = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-CLUST')->first();
            $this->assertNotNull($flow);

            // Assert it contains [H] in sequence ranking 2
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $flow->id, 'sequence_ranking' => 2, 'office_code' => '[H]']);

            // 3. Create a transaction using pages.dts.create.internal
            $qrCode = 'QR-TEST-CLUST-' . time();
            DB::table('dts_qr_code')->insert([
                'code_id' => $qrCode,
                'qr_status' => 'not used',
                'created_at' => now(),
            ]);

            Volt::test('pages.dts.create.internal')
                ->set('unit_college', 'TEST-ORG1')
                ->set('transaction_flow', 'TEST-FLOW-CLUST')
                ->set('generatedQrCode', $qrCode)
                ->set('seq_number', '12345')
                ->set('requestor_name', 'John Doe')
                ->set('subject', 'Test Subject')
                ->set('classification', 'simple')
                ->set('action_needed', 'For approval')
                ->set('copy_furnished', 'No')
                ->call('save')
                ->assertHasNoErrors();

            // Verify the created transaction's custom flow resolves [H] to TEST-CH1
            $transaction = DB::table('dts_transaction_details')
                ->where('control_number', 'INT-' . now()->format('Y-m') . '-12345')
                ->first();
            $this->assertNotNull($transaction);

            $customFlow = DB::table('dts_transaction_flow')->where('flow_code', $transaction->transaction_flow)->first();
            $this->assertNotNull($customFlow);

            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $customFlow->id, 'sequence_ranking' => 1, 'office_code' => 'ORIGIN']);
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $customFlow->id, 'sequence_ranking' => 2, 'office_code' => '[H]']);
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $customFlow->id, 'sequence_ranking' => 3, 'office_code' => 'TEST-OFF1']);
            $this->assertDatabaseHas('dts_sequence_list', ['control_id' => $customFlow->id, 'sequence_ranking' => 4, 'office_code' => 'ORIGIN']);

            // Cleanup custom flow records
            DB::table('dts_sequence_list')->where('control_id', $customFlow->id)->delete();
            DB::table('dts_transaction_flow')->where('flow_code', $customFlow->flow_code)->delete();
            DB::table('dts_transaction_details')->where('id', $transaction->id)->delete();
            DB::table('dts_transactions')->where('transaction_id', $transaction->id)->delete();
            DB::table('sub_document_tracking_system_logs')->where('transaction_id', $transaction->id)->delete();
            DB::table('dts_qr_code')->where('code_id', $qrCode)->delete();

            // Cleanup imported predefined flow
            DB::table('dts_sequence_list')->where('control_id', $flow->id)->delete();
            DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-CLUST')->delete();

        } finally {
            DB::table('office')->whereIn('office_code', ['TEST-CH1', 'TEST-ORG1'])->delete();
            DB::table('cluster')->where('cluster_code', 'TEST-CLUST1')->delete();
        }
    }

    public function test_import_with_flow_use_and_default_behavior()
    {
        $fileContent = "=Flow With Use;\nTEST-FLOW-USE-IMP;\n[]->TEST-OFF1;\nTEST-OFF2;\n[ internal ];\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSee("Successfully imported 1 predefined flow(s) from the file!");

        $flow = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-USE-IMP')->first();
        $this->assertNotNull($flow);
        $this->assertEquals('internal', $flow->flow_use);

        // Clean up
        DB::table('dts_sequence_list')->where('control_id', $flow->id)->delete();
        DB::table('dts_copy_filled_to_office')->where('control_id', 1001)->delete();
        DB::table('dts_copy_filled_transaction')->where('control_num', 'TEST-FLOW-USE-IMP')->delete();
        DB::table('dts_transaction_flow')->where('id', $flow->id)->delete();
    }

    public function test_import_with_abbreviated_flow_use()
    {
        $fileContent = "=Flow With Abbr;\nTEST-FLOW-ABBR-IMP;\n[]->TEST-OFF1;\n[ INT ];\n";
        $file = UploadedFile::fake()->createWithContent('flows.txt', $fileContent);

        Volt::test('pages.admin.dts.transaction-flows')
            ->set('selectedPredefined', 'import')
            ->set('flowFile', $file)
            ->call('importFlow')
            ->assertHasNoErrors()
            ->assertSee("Successfully imported 1 predefined flow(s) from the file!");

        $flow = DB::table('dts_transaction_flow')->where('flow_code', 'TEST-FLOW-ABBR-IMP')->first();
        $this->assertNotNull($flow);
        $this->assertEquals('internal', $flow->flow_use);

        // Clean up
        DB::table('dts_sequence_list')->where('control_id', $flow->id)->delete();
        DB::table('dts_transaction_flow')->where('id', $flow->id)->delete();
    }

    public function test_predefined_purpose_filter()
    {
        // 1. Insert test flows
        $maxId = DB::table('dts_transaction_flow')->max('id') ?? 0;
        $id1 = $maxId + 1;
        $id2 = $maxId + 2;

        DB::table('dts_transaction_flow')->insert([
            'id' => $id1,
            'flow_name' => 'Active Flow Internal',
            'flow_code' => 'TEST-FLOW-ACT1',
            'is_active' => true,
            'flow_use' => 'internal',
            'added_by' => 1,
            'date_added' => now(),
        ]);

        DB::table('dts_transaction_flow')->insert([
            'id' => $id2,
            'flow_name' => 'Active Flow Application',
            'flow_code' => 'TEST-FLOW-ACT2',
            'is_active' => true,
            'flow_use' => 'application',
            'added_by' => 1,
            'date_added' => now(),
        ]);

        // 2. Verify filter works inside Volt component
        Volt::test('pages.admin.dts.transaction-flows')
            ->set('predefinedPurposeFilter', 'all')
            ->assertViewHas('predefinedFlows', function ($flows) {
                $codes = collect($flows)->pluck('flow_code')->toArray();
                return in_array('TEST-FLOW-ACT1', $codes) && in_array('TEST-FLOW-ACT2', $codes);
            })
            ->set('predefinedPurposeFilter', 'internal')
            ->assertViewHas('predefinedFlows', function ($flows) {
                $codes = collect($flows)->pluck('flow_code')->toArray();
                return in_array('TEST-FLOW-ACT1', $codes) && !in_array('TEST-FLOW-ACT2', $codes);
            })
            ->set('predefinedPurposeFilter', 'application')
            ->assertViewHas('predefinedFlows', function ($flows) {
                $codes = collect($flows)->pluck('flow_code')->toArray();
                return !in_array('TEST-FLOW-ACT1', $codes) && in_array('TEST-FLOW-ACT2', $codes);
            });

        // 3. Clean up
        DB::table('dts_transaction_flow')->whereIn('id', [$id1, $id2])->delete();
    }
}
