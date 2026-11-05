<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpVerificationModalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::first();
        if (!$admin) {
            $userId = DB::table('sys_account')->insertGetId([
                'username' => 'test_admin_' . uniqid(),
                'password' => bcrypt('password'),
                'account_role' => 1,
                'account_status' => 1,
                'account_active' => true,
                'date_created' => now(),
                'date_updated' => now(),
            ]);
            $admin = User::find($userId);
        }

        $office = DB::table('sys_office')->where('is_active', true)->first();
        if ($office && $admin) {
            DB::table('sys_account_details')
                ->updateOrInsert(
                    ['account_id' => $admin->id],
                    [
                        'office_id' => $office->id,
                        'first_name' => 'Admin',
                        'last_name' => 'User',
                        'email' => 'admin@cspc.edu.ph',
                    ]
                );
        }

        $admin = User::find($admin->id);
        Auth::login($admin);
        $this->actingAs($admin);
        \Livewire\Livewire::actingAs($admin);
    }

    private function setValidSingleRecordData($component, array $overrides = []): void
    {
        $mediumId = DB::table('rdp_recorded_value')->whereRaw('LOWER(medium_name) = ?', ['paper'])->value('id') ?? 1;

        $defaults = [
            'selectedSeriesTitle' => 'TEST RECORD SERIES',
            'description'         => 'Test Record Description',
            'date_covered'        => '2024-05-15',
            'volume_amount'       => '5',
            'volume_unit'         => 'Folder',
            'volume'              => '5 Folder',
            'records_location'    => 'Cabinet 1, Shelf A',
            'records_medium'      => $mediumId,
            'restriction'         => 'Restricted',
            'frequence_use'       => 'Daily',
            'utility_values'      => [1],
            'retention_period'    => '5 Years',
        ];

        $data = array_merge($defaults, $overrides);
        foreach ($data as $key => $val) {
            $component->set($key, $val);
        }
    }

    public function test_verification_modal_does_not_open_if_validation_fails(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->set('selectedSeriesTitle', '');
        $component->call('openVerificationModal');

        $component->assertSet('showVerificationModal', false);
        $component->assertSet('showValidationErrors', true);
        $this->assertNotNull($component->get('errorMessage'));
    }

    public function test_verification_modal_opens_when_data_is_valid(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $this->setValidSingleRecordData($component, [
            'selectedSeriesTitle' => 'OFFICE CORRESPONDENCE',
            'description' => 'Official Office Memoranda and Letters 2024',
        ]);

        $component->call('openVerificationModal');

        $component->assertSet('showVerificationModal', true);
        $component->assertSee('Record Verification');
        $component->assertSee('Print Preview');
        $component->assertSee('NAP Form 1 Layout');
        $component->assertSee('NATIONAL ARCHIVES OF THE PHILIPPINES');
        $component->assertSee('Submit as Draft');
        $component->assertSee('Submit');
        $component->assertSee('Cancel');
    }

    public function test_verification_modal_can_be_closed(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $this->setValidSingleRecordData($component);

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);

        $component->call('closeVerificationModal');
        $component->assertSet('showVerificationModal', false);
    }

    public function test_confirm_save_draft_from_verification_modal(): void
    {
        $desc = 'Draft Subject ' . uniqid();
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $this->setValidSingleRecordData($component, [
            'selectedSeriesTitle' => 'DRAFT VERIFICATION SERIES',
            'description' => $desc,
        ]);

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);

        $component->call('confirmSaveDraft');
        $component->assertSet('showVerificationModal', false);

        $savedRecord = DB::table('rdp_record')
            ->where('description', strtoupper($desc))
            ->first();

        $this->assertNotNull($savedRecord);
        $this->assertTrue((bool)$savedRecord->is_draft);
    }

    public function test_confirm_create_record_from_verification_modal(): void
    {
        $desc = 'Active Record Subject ' . uniqid();
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $this->setValidSingleRecordData($component, [
            'selectedSeriesTitle' => 'ACTIVE VERIFICATION SERIES',
            'description' => $desc,
            'is_permanent' => true,
            'retention_period' => 'Permanent',
        ]);

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);

        $component->call('confirmCreateRecord');
        $component->assertSet('showVerificationModal', false);

        $savedRecord = DB::table('rdp_record')
            ->where('description', strtoupper($desc))
            ->first();

        $this->assertNotNull($savedRecord);
        $this->assertFalse((bool)$savedRecord->is_draft);
    }

    public function test_verification_modal_with_collective_offices_notice(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $this->setValidSingleRecordData($component, [
            'spreadToDuplicateOffices' => true,
            'duplicate_offices' => ['O-VPAA'],
        ]);

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);
        $component->assertSee('Duplicate Offices Spread Activated');
        $component->assertSee('O-VPAA');
        $component->assertSee('Submit &amp; Spread to Duplicate Offices', false);
    }

    public function test_standalone_save_draft_button_is_removed(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $html = $component->html();

        // Ensure "SAVE DRAFT" button in actions bar is removed
        $this->assertStringNotContainsString('wire:click="saveDraft"', $html);
        // Ensure Create Record button triggers openVerificationModal
        $this->assertStringContainsString('wire:click="openVerificationModal"', $html);
    }

    public function test_verification_modal_renders_in_batch_mode(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->set('entryMode', 'batch');
        $component->set('selectedSeriesTitle', 'BATCH RECORD SERIES');
        $component->set('description', 'Batch Test Record');
        $component->set('batch_start_date', '2020-01-01');
        $component->set('batch_end_date', '2022-12-31');
        $component->set('batch_sub_periods', [
            ['start_date' => '2020-01-01', 'end_date' => '2020-12-31', 'volume' => '1 Folder', 'description' => '2020 Records'],
            ['start_date' => '2021-01-01', 'end_date' => '2021-12-31', 'volume' => '1 Folder', 'description' => '2021 Records'],
        ]);
        $component->set('records_location', 'Cabinet 1, Shelf 2');
        $component->set('records_medium', 1);
        $component->set('restriction', 'Open Access');
        $component->set('frequence_use', 'Daily');
        $component->set('utility_values', [1]);
        $component->set('retention_period', '5 Years');

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);
        $component->assertSee('2020 Records');
        $component->assertSee('2021 Records');
        $component->assertSee('admin@cspc.edu.ph');
    }

    public function test_verification_modal_renders_office_concern_when_more_than_five_duplicates(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $this->setValidSingleRecordData($component, [
            'selectedSeriesTitle' => 'MANY DUPLICATES SERIES',
            'description' => 'Many Duplicates Test',
            'duplicate_offices' => ['ACCESS', 'President', 'Board Secretary', 'COA', 'FAI', 'SSC', 'IAU'],
        ]);

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);
        $component->assertSee('Office Concern');
    }
}
