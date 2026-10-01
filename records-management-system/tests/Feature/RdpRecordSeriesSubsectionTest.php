<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * "+ Sub" on the inline edit row opens the Add Subsection modal, which nests
 * new rows under an existing series using the same chain rules as the
 * Add Record Series form (shared createSeriesChain() helper).
 */
class RdpRecordSeriesSubsectionTest extends TestCase
{
    private const TYPE_NAME = 'TEST SUBSECTION TYPE';
    private const PARENT_TITLE = 'PARENT SUBTEST';

    private int $typeId = 0;
    private int $parentId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        $this->cleanup();

        $this->typeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name' => self::TYPE_NAME,
            'shorted_type' => 'TESTSB',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->parentId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => self::PARENT_TITLE,
            'series_type' => $this->typeId,
            'is_active' => true,
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    private function cleanup(): void
    {
        $typeIds = DB::table('rdp_record_series_type')
            ->where('type_name', self::TYPE_NAME)
            ->pluck('id')
            ->all();

        if (!empty($typeIds)) {
            DB::table('rdp_record_series')->whereIn('series_type', $typeIds)->delete();
        }

        DB::table('rdp_record_series_type')->where('type_name', self::TYPE_NAME)->delete();
    }

    public function test_edit_row_offers_add_subsection_button(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->call('startEdit', $this->parentId)
            ->assertSet('editingId', $this->parentId)
            ->assertSee('wire:click="openSubsectionModal(' . $this->parentId . ')"', false);
    }

    public function test_add_form_still_chains_subsections_after_refactor(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->set('series_title', 'CHAIN ROOT')
            ->set('subsections', ['CHAIN CHILD', 'CHAIN GRANDCHILD'])
            ->call('addSeries')
            ->assertSet('errorMessage', '')
            ->assertSet('successMessage', fn ($message) => str_contains($message, 'hierarchy created'));

        $root = DB::table('rdp_record_series')
            ->where('series_title', 'CHAIN ROOT')
            ->whereNull('parent_id')
            ->first();
        $child = DB::table('rdp_record_series')
            ->where('series_title', 'CHAIN CHILD')
            ->first();
        $grandChild = DB::table('rdp_record_series')
            ->where('series_title', 'CHAIN GRANDCHILD')
            ->first();

        $this->assertNotNull($root);
        $this->assertNotNull($child);
        $this->assertNotNull($grandChild);
        $this->assertSame($this->typeId, (int) $root->series_type);
        $this->assertSame((int) $root->id, (int) $child->parent_id);
        $this->assertSame((int) $child->id, (int) $grandChild->parent_id);
    }

    public function test_subsection_modal_opens_with_parent_context_and_closes(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->call('openSubsectionModal', $this->parentId)
            ->assertSet('showSubsectionModal', true)
            ->assertSet('subsectionParentId', $this->parentId)
            ->assertSet('subsectionParentTitle', self::PARENT_TITLE)
            ->assertSee('Add Subsection')
            ->call('closeSubsectionModal')
            ->assertSet('showSubsectionModal', false)
            ->assertSet('subsectionParentId', 0)
            ->assertSet('subsectionTitles', '');
    }

    public function test_save_subsections_nests_rows_and_inherits_attributes(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->call('openSubsectionModal', $this->parentId)
            ->set('subsectionTitles', "CHILD ALPHA\nGRANDCHILD BETA")
            ->set('subsectionItemNumber', '77')
            ->call('saveSubsections')
            ->assertSet('showSubsectionModal', false)
            ->assertSet('errorMessage', '')
            ->assertSet('successMessage', fn ($message) => str_contains($message, 'added under'));

        $child = DB::table('rdp_record_series')->where('series_title', 'CHILD ALPHA')->first();
        $grandChild = DB::table('rdp_record_series')->where('series_title', 'GRANDCHILD BETA')->first();

        $this->assertNotNull($child);
        $this->assertNotNull($grandChild);

        // Direct child of the edited series, inheriting bracket / office / type.
        $this->assertSame($this->parentId, (int) $child->parent_id);
        $this->assertNull($child->bracket_id);
        $this->assertNull($child->recorded_at_office);
        $this->assertSame($this->typeId, (int) $child->series_type);
        $this->assertTrue((bool) $child->is_verified);
        $this->assertTrue((bool) $child->is_active);
        $this->assertSame(77, (int) $child->item_number);

        // Second line nests under the first; item no. applies to the first only.
        $this->assertSame((int) $child->id, (int) $grandChild->parent_id);
        $this->assertNull($grandChild->item_number);
    }

    public function test_save_subsections_validates_input_and_keeps_modal_open(): void
    {
        $component = Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->call('openSubsectionModal', $this->parentId);

        // Blank / whitespace-only titles are rejected.
        $component->set('subsectionTitles', "   \n  ")
            ->call('saveSubsections')
            ->assertSet('showSubsectionModal', true)
            ->assertSet('errorMessage', 'Enter at least one subsection title (one per line).');

        // Non-numeric item numbers are rejected before anything is written.
        $component->set('subsectionTitles', 'VALID CHILD')
            ->set('subsectionItemNumber', 'abc')
            ->call('saveSubsections')
            ->assertSet('showSubsectionModal', true)
            ->assertSet('errorMessage', 'Item No. must be a number.');

        $this->assertSame(
            0,
            DB::table('rdp_record_series')->where('series_title', 'VALID CHILD')->count()
        );
    }

    public function test_saving_same_subsection_twice_does_not_duplicate(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->call('openSubsectionModal', $this->parentId)
            ->set('subsectionTitles', 'DUP CHILD')
            ->call('saveSubsections')
            ->assertSet('errorMessage', '');

        Volt::test('pages.admin.rdp.record-series')
            ->call('openSubsectionModal', $this->parentId)
            ->set('subsectionTitles', 'dup child') // case-insensitive match reuses the row
            ->call('saveSubsections')
            ->assertSet('errorMessage', '');

        $this->assertSame(
            1,
            DB::table('rdp_record_series')
                ->where('series_title', 'ilike', 'DUP CHILD')
                ->where('parent_id', $this->parentId)
                ->count()
        );
    }
}
