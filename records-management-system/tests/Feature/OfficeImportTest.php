<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OfficeImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Authenticate admin user
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        // Clean up potential test offices
        DB::table('office')->whereIn('office_code', ['TEST-IMP-OFF1', 'TEST-IMP-OFF2', 'TEST-IMP-OFF3'])->delete();
    }

    protected function tearDown(): void
    {
        DB::table('office')->whereIn('office_code', ['TEST-IMP-OFF1', 'TEST-IMP-OFF2', 'TEST-IMP-OFF3'])->delete();
        parent::tearDown();
    }

    public function test_successful_office_import_with_optional_status()
    {
        // Office 1 has no status line (defaults to active), Office 2 has status line (false), Office 3 has status line (true)
        $fileContent = "Test Import Office One;\nTEST-IMP-OFF1;\nTest Import Office Two;\nTEST-IMP-OFF2;\nfalse;\nTest Import Office Three;\nTEST-IMP-OFF3;\ntrue;\n";
        $file = UploadedFile::fake()->createWithContent('offices.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -2)
            ->set('officeFile', $file)
            ->call('importOffices')
            ->assertHasNoErrors()
            ->assertSet('selectedOfficeId', null)
            ->assertSee("Successfully imported 3 office(s) from the file!");

        // Assert database values
        $this->assertDatabaseHas('office', ['office_code' => 'TEST-IMP-OFF1', 'office_name' => 'Test Import Office One', 'is_active' => true]);
        $this->assertDatabaseHas('office', ['office_code' => 'TEST-IMP-OFF2', 'office_name' => 'Test Import Office Two', 'is_active' => false]);
        $this->assertDatabaseHas('office', ['office_code' => 'TEST-IMP-OFF3', 'office_name' => 'Test Import Office Three', 'is_active' => true]);
    }

    public function test_office_import_fails_on_missing_semicolon()
    {
        $fileContent = "Test Import Office One;\nTEST-IMP-OFF1\n";
        $file = UploadedFile::fake()->createWithContent('offices.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -2)
            ->set('officeFile', $file)
            ->call('importOffices')
            ->assertSet('errorMessage', 'Extraction failed: Line 2 ("TEST-IMP-OFF1") must end with a semicolon \';\'.');
    }

    public function test_office_import_skips_perfect_db_duplicate()
    {
        DB::table('office')->insert([
            'office_name' => 'Duplicate Office',
            'office_code' => 'TEST-IMP-OFF1',
            'is_active' => true,
        ]);

        $fileContent = "Duplicate Office;\nTEST-IMP-OFF1;\nTest Import Office Two;\nTEST-IMP-OFF2;\n";
        $file = UploadedFile::fake()->createWithContent('offices.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -2)
            ->set('officeFile', $file)
            ->call('importOffices')
            ->assertHasNoErrors()
            ->assertSet('selectedOfficeId', null)
            ->assertSee("Successfully imported 1 office(s) from the file!");

        $this->assertDatabaseHas('office', ['office_code' => 'TEST-IMP-OFF2', 'office_name' => 'Test Import Office Two']);

        DB::table('office')->where('office_code', 'TEST-IMP-OFF1')->delete();
    }

    public function test_office_import_fails_on_mismatch_db_duplicate()
    {
        DB::table('office')->insert([
            'office_name' => 'Duplicate Office',
            'office_code' => 'TEST-IMP-OFF1',
            'is_active' => true,
        ]);

        $fileContent = "Different Office Name;\nTEST-IMP-OFF1;\n";
        $file = UploadedFile::fake()->createWithContent('offices.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -2)
            ->set('officeFile', $file)
            ->call('importOffices')
            ->assertSet('errorMessage', 'Extraction failed: Line 2 ("TEST-IMP-OFF1;"): Conflict detected. The office code \'TEST-IMP-OFF1\' already exists in the database with a different name (\'Duplicate Office\').');

        DB::table('office')->where('office_code', 'TEST-IMP-OFF1')->delete();
    }

    public function test_import_ignores_comment_lines()
    {
        // Incorporate lines starting with # which should be completely skipped
        $fileContent = "# This is a comment at the top\nTest Import Office One;\nTEST-IMP-OFF1;\n# Another comment in the middle\n";
        $file = UploadedFile::fake()->createWithContent('offices.txt', $fileContent);

        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', -2)
            ->set('officeFile', $file)
            ->call('importOffices')
            ->assertHasNoErrors()
            ->assertSet('selectedOfficeId', null)
            ->assertSee("Successfully imported 1 office(s) from the file!");

        $this->assertDatabaseHas('office', ['office_code' => 'TEST-IMP-OFF1', 'office_name' => 'Test Import Office One']);
    }

    public function test_origin_office_cannot_be_deactivated_or_deleted()
    {
        // 1. Fetch ORIGIN office record
        $originOffice = DB::table('office')->where('office_code', 'ORIGIN')->first();
        $this->assertNotNull($originOffice);

        // 2. Try deactivating ORIGIN office (isActive = false)
        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', $originOffice->id)
            ->set('officeName', 'Originated Office')
            ->set('officeCode', 'ORIGIN')
            ->set('isActive', false)
            ->call('saveOfficeChanges')
            ->assertHasNoErrors(); // No layout validation errors

        // Assert it is still active in the database (backend override)
        $this->assertDatabaseHas('office', ['id' => $originOffice->id, 'office_code' => 'ORIGIN', 'is_active' => true]);

        // 3. Try deleting ORIGIN office
        Volt::test('pages.admin.accounts.offices')
            ->set('selectedOfficeId', $originOffice->id)
            ->call('deleteOffice')
            ->assertSet('errorMessage', 'Failed to delete office: The system placeholder office \'Originated Office\' (ORIGIN) cannot be deleted.');

        // Assert it is still in the database and active
        $this->assertDatabaseHas('office', ['id' => $originOffice->id, 'office_code' => 'ORIGIN', 'is_active' => true]);
    }
}
