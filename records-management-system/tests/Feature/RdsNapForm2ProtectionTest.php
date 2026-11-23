<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdsNapForm2ProtectionTest extends TestCase
{
    private const PARENT_TITLE = 'TEST VERIFIED ROOT SERIES';
    private int $retentionId = 0;
    private int $verifiedSeriesId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }

        $this->cleanup();

        $this->retentionId = DB::table('rdp_retention_period')->insertGetId([
            'active_period' => '5 Years',
            'storage_period' => '10 Years',
            'total_period' => '15 Years',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->verifiedSeriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title' => self::PARENT_TITLE,
            'retention_period' => $this->retentionId,
            'is_retention_period_permanent' => false,
            'is_verified' => true,
            'is_active' => true,
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
        DB::table('rdp_record_series')
            ->where('series_title', 'like', 'TEST VERIFIED%')
            ->orWhere('series_title', 'like', 'TEST PROPOSED%')
            ->delete();

        if ($this->retentionId > 0) {
            DB::table('rdp_retention_period')->where('id', $this->retentionId)->delete();
        }
    }

    public function test_verified_series_shows_locked_banner_and_disables_retention_inputs(): void
    {
        Volt::test('pages.rdp.add-records.records-and-disposition-schedule')
            ->set('series_title', self::PARENT_TITLE)
            ->call('selectParentSuggestion', self::PARENT_TITLE)
            ->assertSee('Officially Verified Record Series')
            ->assertSee('LOCKED')
            ->assertSee('RECORD SERIES LOCKED (VERIFIED)')
            ->assertSet('active_period', '5 Years')
            ->assertSet('storage_period', '10 Years');
    }

    public function test_submitting_verified_series_directly_is_blocked_and_does_not_mutate(): void
    {
        Volt::test('pages.rdp.add-records.records-and-disposition-schedule')
            ->set('series_title', self::PARENT_TITLE)
            ->call('selectParentSuggestion', self::PARENT_TITLE)
            ->set('active_period', '999 Years')
            ->call('submitToNapForm2')
            ->assertSet('errorMessage', fn ($msg) => str_contains($msg, 'already an officially verified record series'));

        $fresh = DB::table('rdp_retention_period')->where('id', $this->retentionId)->first();
        $this->assertSame('5 Years', $fresh->active_period);
    }

    public function test_proposed_child_under_verified_parent_stages_as_nap_form_2_unverified(): void
    {
        Volt::test('pages.rdp.add-records.records-and-disposition-schedule')
            ->set('series_title', self::PARENT_TITLE)
            ->call('selectParentSuggestion', self::PARENT_TITLE)
            ->call('addSubsection')
            ->set('subsections.0', 'TEST PROPOSED CHILD SUB')
            ->assertSee('Recorded in NAP Form 2')
            ->set('active_period', '2 Years')
            ->set('storage_period', '3 Years')
            ->call('submitToNapForm2')
            ->assertSet('errorMessage', '')
            ->assertSet('successMessage', fn ($msg) => str_contains($msg, 'recorded in NAP Form 2'));

        // Verify parent remains verified
        $parent = DB::table('rdp_record_series')->where('id', $this->verifiedSeriesId)->first();
        $this->assertTrue((bool)$parent->is_verified);

        // Verify child is staged as unverified (NAP Form 2 proposal)
        $child = DB::table('rdp_record_series')
            ->where('series_title', 'TEST PROPOSED CHILD SUB')
            ->where('parent_id', $this->verifiedSeriesId)
            ->first();

        $this->assertNotNull($child);
        $this->assertFalse((bool)$child->is_verified);
    }

    public function test_subsection_restriction_prevents_adding_when_previous_is_blank(): void
    {
        Volt::test('pages.rdp.add-records.records-and-disposition-schedule')
            ->set('series_title', self::PARENT_TITLE)
            ->call('addSubsection')
            ->assertCount('subsections', 1)
            // Attempt to add a 2nd subsection while 1st is empty
            ->call('addSubsection')
            ->assertSet('errorMessage', 'Please fill in the current subsection before adding another one.')
            ->assertCount('subsections', 1);
    }
}
