<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpInventoryAppraisalModesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_default_mode_is_single(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->assertSet('entryMode', 'single');
        $component->assertSet('isBatchMode', false);
    }

    public function test_switch_to_multi_mode(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToMultiMode');
        $component->assertSet('entryMode', 'multi');
        $component->assertSet('isBatchMode', true);
        $this->assertGreaterThanOrEqual(2, count($component->get('batchItems')));
    }

    public function test_switch_to_batch_mode_and_sub_period_generation(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');
        $component->assertSet('entryMode', 'batch');
        $component->assertSet('isBatchMode', false);
        $component->assertSet('showBatchModal', false);
        $this->assertEmpty($component->get('batch_start_date'));
        $this->assertEmpty($component->get('batch_sub_periods'));

        // Test explicit modal open and close
        $component->call('openBatchModal');
        $component->assertSet('showBatchModal', true);
        $component->call('closeBatchModal');
        $component->assertSet('showBatchModal', false);
        $component->call('openBatchModal');
        $component->assertSet('showBatchModal', true);

        // Test auto-updating via DMY inputs (including older year 1998)
        $component->set('batch_start_day', '1');
        $component->set('batch_start_month', '1');
        $component->set('batch_start_year', '1998');
        $component->set('batch_end_day', '31');
        $component->set('batch_end_month', '12');
        $component->set('batch_end_year', '2001');

        $this->assertEquals('1998-01-01', $component->get('batch_start_date'));
        $this->assertEquals('2001-12-31', $component->get('batch_end_date'));

        $subPeriods = $component->get('batch_sub_periods');
        $this->assertCount(4, $subPeriods);

        // Verify sub periods match the yearly breakdown for 1998 to 2001
        $this->assertEquals('1998-01-01', $subPeriods[0]['start_date']);
        $this->assertEquals('1998-12-31', $subPeriods[0]['end_date']);
        $this->assertEquals('1999-01-01', $subPeriods[1]['start_date']);
        $this->assertEquals('1999-12-31', $subPeriods[1]['end_date']);
        $this->assertEquals('2000-01-01', $subPeriods[2]['start_date']);
        $this->assertEquals('2000-12-31', $subPeriods[2]['end_date']);
        $this->assertEquals('2001-01-01', $subPeriods[3]['start_date']);
        $this->assertEquals('2001-12-31', $subPeriods[3]['end_date']);
    }

    public function test_month_and_year_only_without_day_generates_breakdown(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');

        // User enters Month and Year without filling Day (e.g. Jan 2023 to Dec 2025)
        $component->set('batch_start_month', '1');
        $component->set('batch_start_year', '2023');
        $component->set('batch_end_month', '12');
        $component->set('batch_end_year', '2025');

        // Verify day defaulted to 1 and 31
        $this->assertEquals('1', $component->get('batch_start_day'));
        $this->assertEquals('31', $component->get('batch_end_day'));
        $this->assertEquals('2023-01-01', $component->get('batch_start_date'));
        $this->assertEquals('2025-12-31', $component->get('batch_end_date'));

        // Verify breakdown was generated immediately
        $subPeriods = $component->get('batch_sub_periods');
        $this->assertCount(3, $subPeriods);
        $this->assertEquals('2023-01-01', $subPeriods[0]['start_date']);
        $this->assertEquals('2023-12-31', $subPeriods[0]['end_date']);
        $this->assertEquals('2024-01-01', $subPeriods[1]['start_date']);
        $this->assertEquals('2024-12-31', $subPeriods[1]['end_date']);
        $this->assertEquals('2025-01-01', $subPeriods[2]['start_date']);
        $this->assertEquals('2025-12-31', $subPeriods[2]['end_date']);
    }

    public function test_year_only_without_month_or_day_generates_breakdown(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');

        // User enters ONLY Year (leaves both Month and Day blank)
        $component->set('batch_start_year', '2023');
        $component->set('batch_end_year', '2025');

        // Verify month defaulted to 1 and 12, day defaulted to 1 and 31
        $this->assertEquals('1', $component->get('batch_start_month'));
        $this->assertEquals('1', $component->get('batch_start_day'));
        $this->assertEquals('12', $component->get('batch_end_month'));
        $this->assertEquals('31', $component->get('batch_end_day'));
        $this->assertEquals('2023-01-01', $component->get('batch_start_date'));
        $this->assertEquals('2025-12-31', $component->get('batch_end_date'));

        // Verify breakdown was generated immediately
        $subPeriods = $component->get('batch_sub_periods');
        $this->assertCount(3, $subPeriods);
        $this->assertEquals('2023-01-01', $subPeriods[0]['start_date']);
        $this->assertEquals('2023-12-31', $subPeriods[0]['end_date']);
        $this->assertEquals('2024-01-01', $subPeriods[1]['start_date']);
        $this->assertEquals('2024-12-31', $subPeriods[1]['end_date']);
        $this->assertEquals('2025-01-01', $subPeriods[2]['start_date']);
        $this->assertEquals('2025-12-31', $subPeriods[2]['end_date']);
    }

    public function test_add_and_edit_sub_period_recalculates_master_dates(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');

        // Setup 3 periods (as in user screenshot: 2024 to 2026)
        $component->set('batch_start_day', '1');
        $component->set('batch_start_month', '2');
        $component->set('batch_start_year', '2024');
        $component->set('batch_end_day', '30');
        $component->set('batch_end_month', '12');
        $component->set('batch_end_year', '2026');

        $this->assertEquals('2024-02-01', $component->get('batch_start_date'));
        $this->assertEquals('2026-12-30', $component->get('batch_end_date'));
        $this->assertCount(3, $component->get('batch_sub_periods'));

        // Add a new period range
        $component->call('addBatchSubPeriod');

        // Verify row 4 is generated for next year (2027)
        $subPeriods = $component->get('batch_sub_periods');
        $this->assertCount(4, $subPeriods);
        $this->assertEquals('2027-01-01', $subPeriods[3]['start_date']);
        $this->assertEquals('2027-12-31', $subPeriods[3]['end_date']);
        $this->assertEquals('1', $subPeriods[3]['start_day']);
        $this->assertEquals('1', $subPeriods[3]['start_month']);
        $this->assertEquals('2027', $subPeriods[3]['start_year']);
        $this->assertEquals('31', $subPeriods[3]['end_day']);
        $this->assertEquals('12', $subPeriods[3]['end_month']);
        $this->assertEquals('2027', $subPeriods[3]['end_year']);

        // Verify master batch date range auto-updated and expanded!
        $this->assertEquals('2027-12-31', $component->get('batch_end_date'));
        $this->assertEquals('2027', $component->get('batch_end_year'));
        $this->assertEquals('12', $component->get('batch_end_month'));
        $this->assertEquals('31', $component->get('batch_end_day'));

        // Test editing a sub-period's date via updatedBatchSubPeriods hook
        $component->set('batch_sub_periods.0.start_month', '1');
        $this->assertEquals('2024-01-01', $component->get('batch_sub_periods.0.start_date'));
        $this->assertEquals('2024-01-01', $component->get('batch_start_date'));

        // Test editing calendar start_date directly
        $component->set('batch_sub_periods.0.start_date', '2023-06-15');
        $this->assertEquals('2023-06-15', $component->get('batch_start_date'));
        $this->assertEquals('2023', $component->get('batch_start_year'));
        $this->assertEquals('6', $component->get('batch_start_month'));
        $this->assertEquals('15', $component->get('batch_start_day'));

        // Test editing calendar end_date directly
        $component->set('batch_sub_periods.3.end_date', '2028-05-20');
        $this->assertEquals('2028-05-20', $component->get('batch_end_date'));
        $this->assertEquals('2028', $component->get('batch_end_year'));

        // Test removing row 4 recalculates master end date back
        $component->call('removeBatchSubPeriod', 3);
        $this->assertCount(3, $component->get('batch_sub_periods'));
        $this->assertEquals('2026-12-30', $component->get('batch_end_date'));
        $this->assertEquals('2026', $component->get('batch_end_year'));
    }

    public function test_multi_unit_volume_compilation_and_conversion(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');

        $component->set('batch_start_date', '2022-01-01');
        $component->set('batch_end_date', '2024-12-31');
        $component->call('generateBatchSubPeriods');

        // Test exact user request inputs:
        // - 10 Boxes 2 Papers
        // - 2 Folder 10 Papers
        // - 30 Papers
        $component->set('batch_sub_periods.0.volume', '10 Boxes 2 Papers');
        $component->set('batch_sub_periods.1.volume', '2 Folder 10 Papers');
        $component->set('batch_sub_periods.2.volume', '30 Papers');

        // Total: 10 Boxes, 2 Folders, 42 Papers
        $this->assertEquals('10 Boxes, 2 Folders, 42 Papers', $component->instance()->getBatchTotalVolumeFormatted());

        // Test with built-in conversion: 100 Pages = 1 Folder
        // Change third sub-period to 88 Papers: 2 + 10 + 88 = 100 Papers -> converts to 1 Folder!
        $component->set('batch_sub_periods.2.volume', '88 Papers');
        $this->assertEquals('10 Boxes, 3 Folders', $component->instance()->getBatchTotalVolumeFormatted());
    }

    public function test_batch_dropdown_toggle(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');
        $component->assertSet('batch_dropdown_expanded', true);
        $component->call('toggleBatchDropdown');
        $component->assertSet('batch_dropdown_expanded', false);
        $component->call('toggleBatchDropdown');
        $component->assertSet('batch_dropdown_expanded', true);
    }

    public function test_switch_back_to_single_mode(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');
        $component->assertSet('entryMode', 'batch');
        $component->call('switchToSingleMode');
        $component->assertSet('entryMode', 'single');
    }

    public function test_create_record_in_batch_mode(): void
    {
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal');
        $component->call('switchToBatchMode');

        $component->set('selectedSeriesTitle', 'TEST SER_BATCH_MODE_SERIES');
        $component->set('description', 'TEST BATCH RECORD DESCRIPTION');
        $component->set('batch_start_day', '1');
        $component->set('batch_start_month', '1');
        $component->set('batch_start_year', '2022');
        $component->set('batch_end_day', '31');
        $component->set('batch_end_month', '12');
        $component->set('batch_end_year', '2024');

        $component->set('batch_sub_periods.0.volume', '10 Boxes 2 Papers');
        $component->set('batch_sub_periods.1.volume', '2 Folder 10 Papers');
        $component->set('batch_sub_periods.2.volume', '30 Papers');

        $component->call('createRecord');
        $this->assertNull($component->get('errorMessage'), 'Error: ' . $component->get('errorMessage'));
        $component->assertHasNoErrors();

        // Verify record in database
        $record = DB::table('rdp_record')
            ->where('description', 'TEST BATCH RECORD DESCRIPTION')
            ->latest('id')
            ->first();

        $this->assertNotNull($record);
        $this->assertEquals('10 BOXES, 2 FOLDERS, 42 PAPERS', $record->volume);
        $this->assertFalse((bool)$record->is_draft);
        $this->assertTrue((bool)$record->ispartof_batch);
        $this->assertNotNull($record->batch_id);

        // Verify period covered records in database retain exact user inputs
        $periods = DB::table('rdp_period_covered')
            ->where('period_owner', $record->id)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertCount(3, $periods);
        $this->assertEquals('2022-01-01', $periods[0]->date_covered);
        $this->assertEquals('10 Boxes 2 Papers', $periods[0]->volume);
        $this->assertEquals('2023-01-01', $periods[1]->date_covered);
        $this->assertEquals('2 Folder 10 Papers', $periods[1]->volume);
        $this->assertEquals('2024-01-01', $periods[2]->date_covered);
        $this->assertEquals('30 Papers', $periods[2]->volume);

        // Cleanup
        $bId = $record->batch_id;
        DB::table('rdp_period_covered')->where('period_owner', $record->id)->delete();
        DB::table('rdp_record')->where('id', $record->id)->delete();
        if ($bId) {
            DB::table('rdp_batch')->where('id', $bId)->delete();
        }
        DB::table('rdp_record_series')->where('series_title', 'TEST SER_BATCH_MODE_SERIES')->delete();
    }
}
