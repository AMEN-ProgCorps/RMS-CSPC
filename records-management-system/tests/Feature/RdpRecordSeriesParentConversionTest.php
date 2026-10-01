<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The "Subsection of…" picker in the inline edit row converts an existing
 * record series into a subsection of another one — and back to a top-level
 * series by clearing the field. A row can never become its own ancestor, and
 * converting re-files the branch under the new parent's bracket so it nests
 * correctly in the grouped view (Item No. is left untouched).
 */
class RdpRecordSeriesParentConversionTest extends TestCase
{
    private const TYPE_A = 'TEST PARENT TYPE A';
    private const TYPE_B = 'TEST PARENT TYPE B';
    private const BRACKET_ONE = 'TEST BRACKET ONE';
    private const BRACKET_TWO = 'TEST BRACKET TWO';

    private int $typeAId = 0;
    private int $bracketOneId = 0;
    private int $bracketTwoId = 0;
    private int $workProgramsId = 0;
    private int $topAlphaId = 0;
    private int $alphaChildId = 0;
    private int $otherTypeRowId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        $this->cleanup();

        $typeAId = $this->insertType(self::TYPE_A, 'TPA');
        $typeBId = $this->insertType(self::TYPE_B, 'TPB');
        $this->typeAId = $typeAId;

        $this->bracketOneId = $this->insertBracket(self::BRACKET_ONE);
        $this->bracketTwoId = $this->insertBracket(self::BRACKET_TWO);

        $this->workProgramsId = $this->insertSeries('WORK PROGRAMS', $typeAId, 35, null, $this->bracketOneId, true);
        $this->topAlphaId = $this->insertSeries('TOP ALPHA', $typeAId, 40, null, null, true);

        // Inactive so it never renders as its own display row — if it ever shows
        // up in the parent suggestions, the subtree exclusion is broken.
        $this->alphaChildId = $this->insertSeries('ALPHA CHILD', $typeAId, null, $this->topAlphaId, $this->bracketTwoId, false);

        $this->otherTypeRowId = $this->insertSeries('OTHER TYPE ROW', $typeBId, null, null, null, true);
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    private function cleanup(): void
    {
        $typeIds = DB::table('rdp_record_series_type')
            ->whereIn('type_name', [self::TYPE_A, self::TYPE_B])
            ->pluck('id')
            ->all();

        if (!empty($typeIds)) {
            DB::table('rdp_record_series')->whereIn('series_type', $typeIds)->update(['parent_id' => null]);
            DB::table('rdp_record_series')->whereIn('series_type', $typeIds)->delete();
        }

        DB::table('rdp_record_series_type')->whereIn('type_name', [self::TYPE_A, self::TYPE_B])->delete();
        DB::table('rdp_record_series_brackets')->whereIn('bracket_name', [self::BRACKET_ONE, self::BRACKET_TWO])->delete();
    }

    private function insertType(string $name, string $short): int
    {
        return DB::table('rdp_record_series_type')->insertGetId([
            'type_name'   => $name,
            'shorted_type' => $short,
            'is_active'   => true,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    private function insertBracket(string $name): int
    {
        return DB::table('rdp_record_series_brackets')->insertGetId([
            'bracket_name' => $name,
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function insertSeries(string $title, int $typeId, ?int $itemNumber, ?int $parentId, ?int $bracketId, bool $active): int
    {
        return DB::table('rdp_record_series')->insertGetId([
            'series_title'   => $title,
            'series_type'    => $typeId,
            'item_number'    => $itemNumber,
            'parent_id'      => $parentId,
            'bracket_id'     => $bracketId,
            'is_active'      => $active,
            'is_verified'    => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_edit_row_offers_parent_picker_with_scoped_suggestions(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeAId)
            ->call('startEdit', $this->topAlphaId)
            ->set('showEditParentDropdown', true)
            ->assertSee('Subsection of… (blank = top level)')
            ->assertSee('wire:click="selectEditParentSuggestion(' . $this->workProgramsId . ')"', false)
            ->assertSee('#35')
            ->assertSee('Top level')
            // Same type only — never the edited row itself, its own subsection,
            // or a row from another record series type:
            ->assertDontSee('selectEditParentSuggestion(' . $this->topAlphaId . ')')
            ->assertDontSee('selectEditParentSuggestion(' . $this->alphaChildId . ')')
            ->assertDontSee('selectEditParentSuggestion(' . $this->otherTypeRowId . ')')
            ->call('clearEditParent')
            ->assertSet('editParentInput', '')
            ->assertSet('editParentId', null)
            ->assertSet('showEditParentDropdown', false);
    }

    public function test_converting_to_subsection_nests_and_refiles_the_branch(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeAId)
            ->call('startEdit', $this->topAlphaId)
            ->assertSet('editParentId', null)
            ->assertSet('editParentInput', '')
            ->call('selectEditParentSuggestion', $this->workProgramsId)
            ->assertSet('editParentId', $this->workProgramsId)
            ->assertSet('editParentInput', 'WORK PROGRAMS')
            ->call('updateSeries')
            ->assertSet('editingId', null)
            ->assertSet('errorMessage', '');

        $top = DB::table('rdp_record_series')->find($this->topAlphaId);
        $this->assertSame($this->workProgramsId, (int) $top->parent_id);
        $this->assertSame(40, (int) $top->item_number);                      // Item No. untouched
        $this->assertSame($this->bracketOneId, (int) $top->bracket_id);      // re-filed into parent's group

        $child = DB::table('rdp_record_series')->find($this->alphaChildId);
        $this->assertSame($this->topAlphaId, (int) $child->parent_id);       // subtree stays intact
        $this->assertSame($this->bracketOneId, (int) $child->bracket_id);    // whole branch re-filed
    }

    public function test_clearing_the_field_converts_back_to_top_level(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeAId)
            ->call('startEdit', $this->alphaChildId)
            ->assertSet('editParentId', $this->topAlphaId)
            ->assertSet('editParentInput', 'TOP ALPHA')
            ->set('editParentInput', '')
            ->call('updateSeries')
            ->assertSet('editingId', null)
            ->assertSet('errorMessage', '');

        $child = DB::table('rdp_record_series')->find($this->alphaChildId);
        $this->assertNull($child->parent_id);                                // now a top-level series
        $this->assertSame($this->bracketTwoId, (int) $child->bracket_id);    // detaching keeps its bracket
        $this->assertNull($child->item_number);
    }

    public function test_typing_without_picking_from_the_list_is_blocked(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeAId)
            ->call('startEdit', $this->alphaChildId)
            ->set('editParentInput', 'WORK PROGRAMS')
            ->call('updateSeries')
            ->assertSet('editingId', $this->alphaChildId) // stays in edit mode on error
            ->assertSet(
                'errorMessage',
                'Pick the parent from the suggestion list, or clear the field to keep it a top-level series.'
            );

        $child = DB::table('rdp_record_series')->find($this->alphaChildId);
        $this->assertSame($this->topAlphaId, (int) $child->parent_id);       // unchanged
    }

    public function test_cannot_convert_under_itself_or_its_own_subsection(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeAId)
            ->call('startEdit', $this->topAlphaId)
            // Directly offering itself is a no-op:
            ->call('selectEditParentSuggestion', $this->topAlphaId)
            ->assertSet('editParentId', null)
            ->assertSet('editParentInput', '')
            // Offering its own subsection is refused on save:
            ->call('selectEditParentSuggestion', $this->alphaChildId)
            ->call('updateSeries')
            ->assertSet('editingId', $this->topAlphaId)
            ->assertSet('errorMessage', 'Cannot move a record series under one of its own subsections.');

        $top = DB::table('rdp_record_series')->find($this->topAlphaId);
        $this->assertNull($top->parent_id);
    }

    public function test_plain_save_keeps_the_existing_parent(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeAId)
            ->call('startEdit', $this->alphaChildId)
            ->set('editSeriesTitle', 'ALPHA CHILD RENAMED')
            ->call('updateSeries')
            ->assertSet('errorMessage', '')
            ->assertSet('editingId', null);

        $child = DB::table('rdp_record_series')->find($this->alphaChildId);
        $this->assertSame('ALPHA CHILD RENAMED', $child->series_title);
        $this->assertSame($this->topAlphaId, (int) $child->parent_id);       // parent preserved
        $this->assertSame($this->bracketTwoId, (int) $child->bracket_id);    // no re-filing on plain saves
    }
}
