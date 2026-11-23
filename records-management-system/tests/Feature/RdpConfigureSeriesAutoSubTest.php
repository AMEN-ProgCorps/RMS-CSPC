<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpConfigureSeriesAutoSubTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private string $userOfficeCode;
    private int $parentSeriesId;
    private int $subSeriesId;
    private int $subSubSeriesId;
    private int $leafOnlySeriesId;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
            $this->userId = $admin->id;
        } else {
            $user = User::first();
            if ($user) {
                Auth::login($user);
                $this->userId = $user->id;
            }
        }

        $user = Auth::user();
        $this->userOfficeCode = $user?->details?->office?->office_code ?? $user?->details?->office_code ?? 'ICTU';

        // 1. Create a 3-level hierarchy: Parent -> SubSection -> Sub-SubSection
        $this->parentSeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'TEST PARENT SERIES ' . uniqid(),
            'parent_id' => null,
            'is_active' => true,
            'is_verified' => true,
            'recorded_at_office' => $this->userOfficeCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subSeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'TEST SUB SECTION ' . uniqid(),
            'parent_id' => $this->parentSeriesId,
            'is_active' => true,
            'is_verified' => true,
            'recorded_at_office' => $this->userOfficeCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->subSubSeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'TEST SUB SUB SECTION ' . uniqid(),
            'parent_id' => $this->subSeriesId,
            'is_active' => true,
            'is_verified' => true,
            'recorded_at_office' => $this->userOfficeCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create a leaf parent without any subsections
        $this->leafOnlySeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'TEST LEAF ONLY SERIES ' . uniqid(),
            'parent_id' => null,
            'is_active' => true,
            'is_verified' => true,
            'recorded_at_office' => $this->userOfficeCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_select_parent_auto_adds_subsection_when_subsections_exist(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id);

        $subsections = $component->get('subsections');
        $this->assertCount(1, $subsections);
        $this->assertEquals('', $subsections[0]);
        $this->assertEquals(0, $component->get('activeSubDropdownIndex'));
    }

    public function test_select_parent_without_subsections_does_not_auto_add_subsection(): void
    {
        $leaf = DB::table('rdp_record_series')->find($this->leafOnlySeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $leaf->series_title, $leaf->id);

        $subsections = $component->get('subsections');
        $this->assertCount(0, $subsections);
        $this->assertNull($component->get('activeSubDropdownIndex'));
    }

    public function test_select_sub_suggestion_auto_adds_sub_subsection_when_sub_subsections_exist(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);
        $sub = DB::table('rdp_record_series')->find($this->subSeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id)
            ->call('selectSubSuggestion', 0, $sub->series_title);

        $subsections = $component->get('subsections');
        $this->assertCount(2, $subsections);
        $this->assertEquals(mb_strtoupper($sub->series_title), $subsections[0]);
        $this->assertEquals('', $subsections[1]);
        $this->assertEquals(1, $component->get('activeSubDropdownIndex'));
    }

    public function test_submit_as_draft_saves_draft_and_prevents_duplicate_drafts(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);
        $sub = DB::table('rdp_record_series')->find($this->subSeriesId);
        $leaf = DB::table('rdp_record_series')->find($this->subSubSeriesId);

        $testDesc = 'TEST DRAFT DESCRIPTION ' . uniqid();

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->set('record_series_id', $leaf->id)
            ->set('selectedSeriesTitle', "{$parent->series_title} ➔ {$sub->series_title} ➔ {$leaf->series_title}")
            ->set('description', $testDesc)
            ->set('volume', '1 BINDER')
            ->set('records_location', 'ROOM 101')
            ->set('date_covered', '2026-01-01')
            ->set('time_value', 'T')
            ->set('showVerificationModal', true);

        // 1. First submission should save draft
        $component->call('confirmSaveDraft');

        $component->assertHasNoErrors();
        $this->assertFalse($component->get('showVerificationModal'));
        $this->assertStringContainsString('draft', strtolower((string)$component->get('successMessage')));

        // Verify record in database
        $draftCount = DB::table('rdp_record')
            ->where('description', mb_strtoupper($testDesc))
            ->where('is_draft', true)
            ->count();
        $this->assertEquals(1, $draftCount);

        // 2. Second submission with identical data should be intercepted by duplicate checker
        $component2 = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->set('record_series_id', $leaf->id)
            ->set('selectedSeriesTitle', "{$parent->series_title} ➔ {$sub->series_title} ➔ {$leaf->series_title}")
            ->set('description', $testDesc)
            ->set('volume', '1 BINDER')
            ->set('records_location', 'ROOM 101')
            ->set('date_covered', '2026-01-01')
            ->set('time_value', 'T')
            ->set('showVerificationModal', true);

        $component2->call('confirmSaveDraft');

        // Total drafts in database must still be exactly 1 (duplicate avoided!)
        $draftCountAfter = DB::table('rdp_record')
            ->where('description', mb_strtoupper($testDesc))
            ->where('is_draft', true)
            ->count();
        $this->assertEquals(1, $draftCountAfter);

        $this->assertStringContainsString('already been saved', strtolower((string)$component2->get('successMessage')));
    }

    public function test_nap_form_2_notice_is_inactive_when_official_subsections_exist(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id);

        // Auto-added subsection slot is for official children, so notice is inactive
        $this->assertFalse($component->instance()->isNapForm2NoticeActive());
    }

    public function test_nap_form_2_notice_is_active_when_adding_subsection_to_leaf_series(): void
    {
        $leaf = DB::table('rdp_record_series')->find($this->leafOnlySeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $leaf->series_title, $leaf->id)
            ->call('addSubsection');

        // Since leaf parent has no subsections in DB, notice is active
        $this->assertTrue($component->instance()->isNapForm2NoticeActive());
    }

    public function test_nap_form_2_notice_is_active_when_custom_subsection_is_added_beyond_hierarchy(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);
        $sub = DB::table('rdp_record_series')->find($this->subSeriesId);
        $subSub = DB::table('rdp_record_series')->find($this->subSubSeriesId);

        // subSub is a leaf node (has no child series in DB)
        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id)
            ->call('selectSubSuggestion', 0, $sub->series_title)
            ->call('selectSubSuggestion', 1, $subSub->series_title)
            ->call('addSubsection'); // User clicks add subsection beyond leaf

        $this->assertTrue($component->instance()->isNapForm2NoticeActive());
    }

    public function test_cannot_add_another_subsection_when_current_subsection_is_empty(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id);

        // Subsections currently has 1 empty subsection: ['']
        $this->assertCount(1, $component->get('subsections'));

        // Attempting to add another subsection while the current one is empty
        $component->call('addSubsection');

        // Must still have 1 subsection and an error must be reported
        $this->assertCount(1, $component->get('subsections'));
        $this->assertStringContainsString('current subsection', (string)$component->get('seriesModalError'));
    }

    public function test_can_add_another_subsection_after_filling_current_subsection(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id)
            ->set('subsections.0', 'FILLED SUBSECTION TITLE');

        // Now that the current subsection is filled, adding another one succeeds
        $component->call('addSubsection');

        $this->assertCount(2, $component->get('subsections'));
        $this->assertNull($component->get('seriesModalError'));
    }

    public function test_cannot_apply_series_when_empty_subsection_is_present(): void
    {
        $parent = DB::table('rdp_record_series')->find($this->parentSeriesId);

        $component = Volt::test('pages.rdp.add-records.inventory-and-appraisal')
            ->call('selectParentSuggestion', $parent->series_title, $parent->id);

        // Subsections has an empty subsection ['']
        $component->call('saveNewRecordSeries');

        // Should be blocked from applying
        $this->assertStringContainsString('empty', (string)$component->get('seriesModalError'));
        $this->assertNull($component->get('record_series_id'));
    }
}
