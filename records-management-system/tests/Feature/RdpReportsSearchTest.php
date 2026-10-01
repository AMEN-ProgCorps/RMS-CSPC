<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * RDP NAP report search: word-level AND matching.
 *
 * Searching "Pasay Travel" must find "Travel Order to Pasay" (both words,
 * in any order) while hiding rows that only contain one of the words —
 * and swapping a word ("Pasay certificate") must narrow the list to the
 * records containing that word.
 *
 * Covers all three report pages:
 *   nap-form-1 — active records (description / volume / location)
 *   nap-form-2 — unverified series (title / remarks / parent title / item no.)
 *   nap-form-3 — expired records transferred to NAP Form 3
 */
class RdpReportsSearchTest extends TestCase
{
    private const TYPE_NAME = 'NAP SEARCH TEST TYPE';

    private const SERIES_1 = 'NAP SEARCH TEST SERIES 1'; // NAP Form 1 host series
    private const SERIES_3 = 'NAP SEARCH TEST SERIES 3'; // NAP Form 3 host series

    // NAP Form 1 records (active, not transferred)
    private const DESC_TRAVEL = 'Travel Order to Pasay NAP1X';
    private const DESC_CERT   = 'Certificate of Partisipation on Pasay NAP1X';
    private const DESC_BUILD  = 'Pasay Building Completion rate NAP1X';

    // NAP Form 3 records (expired, transferred)
    private const DESC_TRAVEL_3 = 'Travel Order to Pasay NAP3X';
    private const DESC_CERT_3   = 'Certificate of Partisipation on Pasay NAP3X';
    private const DESC_BUILD_3  = 'Pasay Building Completion rate NAP3X';

    // NAP Form 2 series (unverified)
    private const TITLE_TRAVEL_2 = 'Travel Order to Pasay NAP2X';
    private const TITLE_CERT_2   = 'Certificate of Partisipation on Pasay NAP2X';
    private const TITLE_BUILD_2  = 'Pasay Building Completion rate NAP2X';

    private int $typeId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        $this->cleanup();

        $now = now();

        $this->typeId = DB::table('rdp_record_series_type')->insertGetId([
            'type_name'    => self::TYPE_NAME,
            'shorted_type' => 'NAPSST',
            'is_active'    => true,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // Retention for the NAP Form 3 fixtures: "1 year" from a 2000 date
        // covered keeps them expired even when the page runs
        // RdpRetentionService::syncTransferredRecords().
        $retentionId = DB::table('rdp_retention_period')->insertGetId([
            'total_period' => '1 year',
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        $series1Id = DB::table('rdp_record_series')->insertGetId([
            'series_title'        => self::SERIES_1,
            'series_type'         => $this->typeId,
            'recorded_at_office'  => 'Dev',
            'is_active'           => true,
            'is_verified'         => true,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $series3Id = DB::table('rdp_record_series')->insertGetId([
            'series_title'        => self::SERIES_3,
            'series_type'         => $this->typeId,
            'recorded_at_office'  => 'Dev',
            'retention_period'    => $retentionId,
            'is_active'           => true,
            'is_verified'         => true,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        // NAP Form 2 fixtures: unverified series (is_verified = false).
        foreach ([self::TITLE_TRAVEL_2, self::TITLE_CERT_2, self::TITLE_BUILD_2] as $title) {
            DB::table('rdp_record_series')->insert([
                'series_title'       => $title,
                'series_type'        => $this->typeId,
                'recorded_at_office' => 'Dev',
                'is_active'          => true,
                'is_verified'        => false,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
        }

        // NAP Form 1 records: active, not transferred, covered up to today.
        foreach ([self::DESC_TRAVEL, self::DESC_CERT, self::DESC_BUILD] as $desc) {
            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'    => $series1Id,
                'description'         => $desc,
                'office_own'          => 'Dev',
                'is_drafted'          => false,
                'is_draft'            => false,
                'is_active'           => true,
                'is_verified'         => false,
                'transferred_to_nap3' => false,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

            DB::table('rdp_period_covered')->insert([
                'period_owner' => $recordId,
                'date_covered' => now()->format('Y-m-d'),
                'created_at'   => $now,
                'modified_at'  => $now,
            ]);
        }

        // NAP Form 3 records: expired (date covered in 2000) and transferred.
        foreach ([self::DESC_TRAVEL_3, self::DESC_CERT_3, self::DESC_BUILD_3] as $desc) {
            $recordId = DB::table('rdp_record')->insertGetId([
                'record_series_id'       => $series3Id,
                'description'            => $desc,
                'office_own'             => 'Dev',
                'is_drafted'             => false,
                'is_draft'               => false,
                'is_active'              => true,
                'is_verified'            => false,
                'transferred_to_nap3'    => true,
                'transferred_to_nap3_at' => now(),
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            DB::table('rdp_period_covered')->insert([
                'period_owner' => $recordId,
                'date_covered' => '2000-01-01',
                'created_at'   => $now,
                'modified_at'  => $now,
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
        $descriptions = [
            self::DESC_TRAVEL, self::DESC_CERT, self::DESC_BUILD,
            self::DESC_TRAVEL_3, self::DESC_CERT_3, self::DESC_BUILD_3,
        ];

        $recordIds = DB::table('rdp_record')->whereIn('description', $descriptions)->pluck('id');
        if ($recordIds->isNotEmpty()) {
            DB::table('rdp_period_covered')->whereIn('period_owner', $recordIds)->delete();
            DB::table('rdp_record')->whereIn('id', $recordIds)->delete();
        }

        $seriesTitles = [
            self::SERIES_1, self::SERIES_3,
            self::TITLE_TRAVEL_2, self::TITLE_CERT_2, self::TITLE_BUILD_2,
        ];

        $retentionIds = DB::table('rdp_record_series')
            ->whereIn('series_title', $seriesTitles)
            ->whereNotNull('retention_period')
            ->pluck('retention_period');

        DB::table('rdp_record_series')->whereIn('series_title', $seriesTitles)->delete();

        if ($retentionIds->isNotEmpty()) {
            DB::table('rdp_retention_period')->whereIn('id', $retentionIds)->delete();
        }

        DB::table('rdp_record_series_type')->where('type_name', self::TYPE_NAME)->delete();
    }

    public function test_form1_search_matches_records_containing_every_word_in_any_order(): void
    {
        // Both words present, in either order → only the travel order shows.
        Volt::test('pages.rdp.reports.nap-form-1')
            ->set('search', 'Pasay Travel')
            ->assertSee(self::DESC_TRAVEL)
            ->assertDontSee(self::DESC_CERT)
            ->assertDontSee(self::DESC_BUILD);

        Volt::test('pages.rdp.reports.nap-form-1')
            ->set('search', 'Travel Pasay')
            ->assertSee(self::DESC_TRAVEL)
            ->assertDontSee(self::DESC_CERT)
            ->assertDontSee(self::DESC_BUILD);

        // Swapping a word narrows the list to the record containing it.
        Volt::test('pages.rdp.reports.nap-form-1')
            ->set('search', 'Pasay certificate')
            ->assertSee(self::DESC_CERT)
            ->assertDontSee(self::DESC_TRAVEL)
            ->assertDontSee(self::DESC_BUILD);
    }

    public function test_form1_single_word_search_still_returns_every_match(): void
    {
        Volt::test('pages.rdp.reports.nap-form-1')
            ->set('search', 'Pasay')
            ->assertSee(self::DESC_TRAVEL)
            ->assertSee(self::DESC_CERT)
            ->assertSee(self::DESC_BUILD);
    }

    public function test_form1_search_with_a_word_nothing_contains_returns_nothing(): void
    {
        Volt::test('pages.rdp.reports.nap-form-1')
            ->set('search', 'Pasay unicorntower')
            ->assertDontSee(self::DESC_TRAVEL)
            ->assertDontSee(self::DESC_CERT)
            ->assertDontSee(self::DESC_BUILD);
    }

    public function test_form2_series_search_matches_every_word_and_swapping_narrows(): void
    {
        Volt::test('pages.rdp.reports.nap-form-2')
            ->set('search', 'Pasay Travel')
            ->assertSee(self::TITLE_TRAVEL_2)
            ->assertDontSee(self::TITLE_CERT_2)
            ->assertDontSee(self::TITLE_BUILD_2);

        Volt::test('pages.rdp.reports.nap-form-2')
            ->set('search', 'Pasay certificate')
            ->assertSee(self::TITLE_CERT_2)
            ->assertDontSee(self::TITLE_TRAVEL_2)
            ->assertDontSee(self::TITLE_BUILD_2);
    }

    public function test_form3_expired_record_search_matches_every_word_and_swapping_narrows(): void
    {
        Volt::test('pages.rdp.reports.nap-form-3')
            ->set('search', 'Pasay Travel')
            ->assertSee(self::DESC_TRAVEL_3)
            ->assertDontSee(self::DESC_CERT_3)
            ->assertDontSee(self::DESC_BUILD_3);

        Volt::test('pages.rdp.reports.nap-form-3')
            ->set('search', 'Pasay certificate')
            ->assertSee(self::DESC_CERT_3)
            ->assertDontSee(self::DESC_TRAVEL_3)
            ->assertDontSee(self::DESC_BUILD_3);
    }
}
