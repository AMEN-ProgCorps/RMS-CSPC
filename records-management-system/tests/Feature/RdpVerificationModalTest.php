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

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
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
            'collectiveOffices' => ['O-VPAA'],
        ]);

        $component->call('openVerificationModal');
        $component->assertSet('showVerificationModal', true);
        $component->assertSee('Collective Office Spread Activated');
        $component->assertSee('O-VPAA');
        $component->assertSee('Submit &amp; Spread to Collective', false);
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
}
