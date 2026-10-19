<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpConfigureRecordSeriesConditionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_parent_record_with_subsections_cannot_be_applied_without_subsection(): void
    {
        // CERTIFICATES (id 5) has child subsections (Appearance, etc.) in the database
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');
        $component->assertSet('showSeriesModal', true);

        // Select CERTIFICATES without any subsections
        $component->call('selectParentSuggestion', 'CERTIFICATES', 5);
        $component->set('subsections', []);

        // Try to apply
        $component->call('saveNewRecordSeries');

        // Must NOT continue: modal remains open, seriesModalError is populated, selectedSeriesTitle is null
        $component->assertSet('showSeriesModal', true);
        $this->assertNotNull($component->get('seriesModalError'));
        $this->assertStringContainsString('contains subsections', $component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', null);
    }

    public function test_parent_record_with_subsections_can_be_applied_when_valid_subsection_is_provided(): void
    {
        // CERTIFICATES (id 5) + Appearance (id 6, retention_period 5 -> 1 YEAR active)
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');

        $component->call('selectParentSuggestion', 'CERTIFICATES', 5);
        $component->set('subsections', ['Appearance']);

        $component->call('saveNewRecordSeries');

        // Must succeed: modal closes, no error, series title applied
        $component->assertSet('showSeriesModal', false);
        $this->assertNull($component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', 'CERTIFICATES ➔ APPEARANCE');
        $component->assertSet('active_period', '1 YEAR');
    }

    public function test_series_with_remarks_condition_can_be_applied_successfully(): void
    {
        // CORRESPONDENCES (id 13) has subsection Non-routine (id 14) which has retention_period = null but remarks condition present
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');

        $component->call('selectParentSuggestion', 'CORRESPONDENCES', 13);
        $component->set('subsections', ['Non-routine']);

        $component->call('saveNewRecordSeries');

        // Must succeed: modal closes, no error, series title applied, remarks populated, retention_period set to SEE REMARKS
        $component->assertSet('showSeriesModal', false);
        $this->assertNull($component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', 'CORRESPONDENCES ➔ NON-ROUTINE');
        $this->assertNotEmpty($component->get('disposition_provision'));
        $component->assertSet('retention_period', 'SEE REMARKS');
        $component->assertSet('hasPredefinedRemarks', true);
    }

    public function test_subsection_inherits_retention_period_and_remarks_from_parent(): void
    {
        // ACCREDITION OF STUDENT FILES (id 625) + Applications (id 626)
        // Parent has retention_period 385 (3 YEARS) and remarks "After expired"
        // Subsection 626 has retention_period = null, remarks = null
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');

        $component->call('selectParentSuggestion', 'ACCREDITION OF STUDENT FILES', 625);
        $component->set('subsections', ['Applications']);

        $component->call('saveNewRecordSeries');

        // Must succeed via inheritance!
        $component->assertSet('showSeriesModal', false);
        $this->assertNull($component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', 'ACCREDITION OF STUDENT FILES ➔ APPLICATIONS');
        $component->assertSet('active_period', '3 YEARS');
        $component->assertSet('retention_period', '3 YEARS');
        $this->assertStringContainsString('After expired', $component->get('disposition_provision'));
    }

    public function test_series_with_no_active_or_storage_period_and_no_remarks_cannot_be_applied(): void
    {
        // Create an unconfigured parent and subsection with no retention and no remarks
        $parentId = DB::table('rdp_record_series')->insertGetId([
            'series_title'                  => 'EMPTY PARENT ' . uniqid(),
            'parent_id'                     => null,
            'retention_period'              => null,
            'is_retention_period_permanent' => false,
            'remarks'                       => null,
            'is_active'                     => true,
            'created_at'                    => now(),
            'updated_at'                    => now(),
        ]);

        $subId = DB::table('rdp_record_series')->insertGetId([
            'series_title'                  => 'EMPTY SUB ' . uniqid(),
            'parent_id'                     => $parentId,
            'retention_period'              => null,
            'is_retention_period_permanent' => false,
            'remarks'                       => null,
            'is_active'                     => true,
            'created_at'                    => now(),
            'updated_at'                    => now(),
        ]);

        $parent = DB::table('rdp_record_series')->where('id', $parentId)->first();
        $sub = DB::table('rdp_record_series')->where('id', $subId)->first();

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');

        $component->call('selectParentSuggestion', $parent->series_title, $parentId);
        $component->set('subsections', [$sub->series_title]);

        $component->call('saveNewRecordSeries');

        // Must NOT continue: modal remains open, seriesModalError populated
        $component->assertSet('showSeriesModal', true);
        $this->assertNotNull($component->get('seriesModalError'));
        $this->assertStringContainsString('no Active Period, Storage Period, or Remarks condition', $component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', null);
    }

    public function test_standalone_parent_with_retention_period_can_be_applied(): void
    {
        // ACKNOWLEDGEMENT RECEIPTS (id 1) has no subsections and has 1 YEAR active period
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');

        $component->call('selectParentSuggestion', 'ACKNOWLEDGEMENT RECEIPTS', 1);
        $component->set('subsections', []);

        $component->call('saveNewRecordSeries');

        // Must succeed
        $component->assertSet('showSeriesModal', false);
        $this->assertNull($component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', 'ACKNOWLEDGEMENT RECEIPTS');
        $component->assertSet('active_period', '1 YEAR');
    }

    public function test_custom_unknown_series_without_retention_cannot_be_applied(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('openSeriesModal');

        $component->set('parentSeriesTitle', 'COMPLETELY RANDOM UNKNOWN SERIES 12345');
        $component->set('subsections', []);

        $component->call('saveNewRecordSeries');

        // Must NOT continue
        $component->assertSet('showSeriesModal', true);
        $this->assertNotNull($component->get('seriesModalError'));
        $component->assertSet('selectedSeriesTitle', null);
    }
}
