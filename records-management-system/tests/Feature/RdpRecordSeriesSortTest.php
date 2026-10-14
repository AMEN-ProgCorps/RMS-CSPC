<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Record Series table: the "Sort by" selector —
 * Grouped (default) / Item No. / Alphabetical / Time Added.
 *
 * Three fixture rows are created whose three sort orders are all mutually
 * different, so each mode can be asserted exactly:
 *   item no.     MMM (1), AAA (2), ZZZ (3)
 *   alphabetical AAA, MMM, ZZZ
 *   time added   ZZZ (newest), MMM, AAA (oldest)
 */
class RdpRecordSeriesSortTest extends TestCase
{
    private const TYPE_NAME = 'TEST SORT TYPE';
    private const TITLE_AAA = 'AAA SORT TEST';
    private const TITLE_MMM = 'MMM SORT TEST';
    private const TITLE_ZZZ = 'ZZZ SORT TEST';

    private int $typeId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::whereHas('permissions', fn ($q) => $q->where('is_sadm', true)->orWhere('can_access_rdp_admin', true))->first();
        if (!$admin) {
            $admin = User::first();
            if ($admin) {
                DB::table('sys_permissions')->updateOrInsert(
                    ['key_id' => $admin->id],
                    ['is_sadm' => true, 'can_access_rdp_admin' => true]
                );
            }
        }
        if ($admin) {
            $this->actingAs($admin);
        }

        $this->cleanup();

        $this->typeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name' => self::TYPE_NAME,
            'shorted_type' => 'TESTST',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fixtures = [
            ['title' => self::TITLE_ZZZ, 'item' => 3, 'daysAgo' => 1],  // newest
            ['title' => self::TITLE_MMM, 'item' => 1, 'daysAgo' => 5],
            ['title' => self::TITLE_AAA, 'item' => 2, 'daysAgo' => 10], // oldest
        ];

        foreach ($fixtures as $fixture) {
            DB::table('rdp_record_series')->insert([
                'series_title' => $fixture['title'],
                'item_number' => $fixture['item'],
                'series_type' => $this->typeId,
                'is_active' => true,
                'is_verified' => true,
                'created_at' => now()->subDays($fixture['daysAgo']),
                'updated_at' => now()->subDays($fixture['daysAgo']),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();

        parent::tearDown();
    }

    private function cleanup(): void
    {
        $ids = DB::table('rdp_record_series')
            ->where('series_title', 'like', '%SORT TEST')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('rdp_record_series')->whereIn('id', $ids)->delete();
        }

        DB::table('rdp_record_series_type')->where('type_name', self::TYPE_NAME)->delete();
    }

    /**
     * Render the page on the test type tab with the given sort mode and
     * return the fixture titles in the order they were rendered.
     */
    private function renderedTitles(string $sortBy): array
    {
        $titles = [];

        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->set('sortBy', $sortBy)
            ->assertViewHas('records', function ($records) use (&$titles) {
                $titles = collect($records->items())
                    ->pluck('series_title')
                    ->filter(fn ($title) => str_contains((string) $title, 'SORT TEST'))
                    ->values()
                    ->all();

                return true;
            });

        return $titles;
    }

    public function test_sort_selector_renders_all_options_and_defaults_to_item_no(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->assertSet('sortBy', 'item_no')
            ->assertSee('Sort by')
            ->assertSee('value="item_no"', false)
            ->assertSee('value="alphabetical"', false)
            ->assertSee('value="time_added"', false);
    }

    public function test_changing_sort_resets_to_first_page(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->set('paginators', ['page' => 3])
            ->set('sortBy', 'alphabetical')
            ->assertSet('paginators', ['page' => 1]);
    }

    public function test_default_sort_by_item_number(): void
    {
        $this->assertSame(
            [self::TITLE_MMM, self::TITLE_AAA, self::TITLE_ZZZ],
            $this->renderedTitles('item_no')
        );
    }

    public function test_sort_alphabetically(): void
    {
        $this->assertSame(
            [self::TITLE_AAA, self::TITLE_MMM, self::TITLE_ZZZ],
            $this->renderedTitles('alphabetical')
        );
    }

    public function test_sort_by_time_added_newest_first(): void
    {
        $this->assertSame(
            [self::TITLE_ZZZ, self::TITLE_MMM, self::TITLE_AAA],
            $this->renderedTitles('time_added')
        );
    }

    public function test_invalid_sort_value_falls_back_to_default(): void
    {
        $defaultIds = [];
        $fallbackIds = [];

        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->assertViewHas('records', function ($records) use (&$defaultIds) {
                $defaultIds = array_column($records->items(), 'id');

                return true;
            });

        Volt::test('pages.admin.rdp.record-series')
            ->call('selectTab', (string) $this->typeId)
            ->set('sortBy', 'bogus-mode')
            ->assertViewHas('records', function ($records) use (&$fallbackIds) {
                $fallbackIds = array_column($records->items(), 'id');

                return true;
            });

        $this->assertNotEmpty($defaultIds);
        $this->assertSame($defaultIds, $fallbackIds);
    }

    public function test_offices_within_bracket_are_ordered_by_item_number_not_alphabetical_office_name(): void
    {
        $bracketId = DB::table('rdp_record_series_brackets')->insertGetId([
            'bracket_name' => 'BRACKET ORDER TEST ' . uniqid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert items where an office without a sys_office entry has a lower item number (23)
        // than an office with a sys_office entry (33)
        $idAcademic = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'ACADEMIC DEPT ITEM ' . uniqid(),
            'item_number' => 23,
            'bracket_id' => $bracketId,
            'recorded_at_office' => 'ACADEMIC DEPARTMENT',
            'series_type' => $this->typeId,
            'is_active' => true,
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idGuidance = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'GUIDANCE ITEM ' . uniqid(),
            'item_number' => 33,
            'bracket_id' => $bracketId,
            'recorded_at_office' => 'Guidance',
            'series_type' => $this->typeId,
            'is_active' => true,
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idDev = DB::table('rdp_record_series')->insertGetId([
            'series_title' => 'DEV ITEM ' . uniqid(),
            'item_number' => 39,
            'bracket_id' => $bracketId,
            'recorded_at_office' => 'Dev',
            'series_type' => $this->typeId,
            'is_active' => true,
            'is_verified' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $renderedIds = [];
            Volt::test('pages.admin.rdp.record-series')
                ->call('selectTab', (string) $this->typeId)
                ->set('sortBy', 'item_no')
                ->assertViewHas('records', function ($records) use (&$renderedIds, $idAcademic, $idGuidance, $idDev) {
                    $ids = array_column($records->items(), 'id');
                    $renderedIds = array_values(array_filter($ids, fn ($id) => in_array($id, [$idAcademic, $idGuidance, $idDev])));
                    return true;
                });

            // Academic Department (Item 23) must precede Guidance (Item 33) and Dev (Item 39)
            $this->assertSame([$idAcademic, $idGuidance, $idDev], $renderedIds);
        } finally {
            DB::table('rdp_record_series')->whereIn('id', [$idAcademic, $idGuidance, $idDev])->delete();
            DB::table('rdp_record_series_brackets')->where('id', $bracketId)->delete();
        }
    }
}
