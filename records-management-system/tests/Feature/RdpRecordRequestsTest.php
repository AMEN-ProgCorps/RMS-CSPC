<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RdpRecordRequestsTest extends TestCase
{
    protected ?User $admin = null;
    protected string $originOffice = 'RFOI';
    protected string $targetOffice = 'ICTU';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first()
            ?? User::first();
        if ($this->admin) {
            $this->actingAs($this->admin);
            \Livewire\Livewire::actingAs($this->admin);
        }

        // Ensure offices exist
        $offTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        DB::table($offTbl)->updateOrInsert(
            ['office_code' => $this->originOffice],
            ['office_name' => 'Records & Freedom of Information Office', 'is_active' => true]
        );
        DB::table($offTbl)->updateOrInsert(
            ['office_code' => $this->targetOffice],
            ['office_name' => 'Information & Communications Technology Unit', 'is_active' => true]
        );
    }

    public function test_inter_office_record_creation_generates_pending_request(): void
    {
        $comp = Volt::test('pages.rdp.add-records.inventory-and-appraisal');

        $seriesTitle = 'TEST INTER OFFICE SERIES ' . uniqid();
        $desc = 'Inventory record created for ICTU by another office ' . uniqid();

        $comp->set('selectedSeriesTitle', $seriesTitle);
        $comp->set('description', $desc);
        $comp->set('date_covered', '2026-05-15');
        $comp->set('volume', '2 FOLDERS');
        $comp->set('records_location', 'ICTU Server Room');
        $comp->set('records_medium', 1);
        $comp->set('restriction', 'Restricted');
        $comp->set('frequence_use', 'Annually');
        $comp->set('utility_values', [1]);
        $comp->set('collectiveOffices', [$this->targetOffice]);

        $comp->call('createRecord');

        if ($comp->get('errorMessage')) {
            $this->fail('createRecord failed with error: ' . $comp->get('errorMessage'));
        }

        // Verify master record for creator office exists and is active
        $userOffice = Auth::user()?->details?->office?->office_code ?? Auth::user()?->details?->office_code ?? null;
        $masterRec = DB::table('rdp_record')
            ->where('description', mb_strtoupper($desc))
            ->where('is_request', false)
            ->first();
        $this->assertNotNull($masterRec);
        $this->assertTrue((bool)$masterRec->is_active);
        $this->assertEquals($userOffice, $masterRec->office_own);
        $this->assertNotNull($masterRec->request_group_id);

        // Verify spread duplicate request in database for target office
        $spreadRec = DB::table('rdp_record')
            ->where('description', mb_strtoupper($desc))
            ->where('is_request', true)
            ->where('request_target_office', $this->targetOffice)
            ->first();
        $this->assertNotNull($spreadRec);
        $this->assertEquals('pending', $spreadRec->request_status);
        $this->assertFalse((bool)$spreadRec->is_active);
        $this->assertEquals($this->targetOffice, $spreadRec->office_own);
        $this->assertEquals($masterRec->request_group_id, $spreadRec->request_group_id);

        // Verify target office received a notification
        $notifTbl = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $notifCount = DB::table($notifTbl)->where('office', $this->targetOffice)->count();
        $this->assertGreaterThanOrEqual(1, $notifCount);

        // Clean up
        DB::table('rdp_record')->where('id', $masterRec->id)->delete();
        DB::table('rdp_record')->where('id', $spreadRec->id)->delete();
        DB::table('rdp_record_series')->where('series_title', $seriesTitle)->delete();
    }

    public function test_record_requests_page_displays_incoming_and_submitted(): void
    {
        $seriesTitle = 'REQUEST VIEW TEST ' . uniqid();
        $desc = 'Test description for request view ' . uniqid();

        // Insert dummy request
        $seriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title'       => $seriesTitle,
            'recorded_at_office' => $this->targetOffice,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $recId = DB::table('rdp_record')->insertGetId([
            'record_series_id'      => $seriesId,
            'description'           => $desc,
            'volume'                => '1 FOLDER',
            'is_request'            => true,
            'request_status'        => 'pending',
            'request_origin_office' => $this->originOffice,
            'request_target_office' => $this->targetOffice,
            'office_own'            => $this->targetOffice,
            'is_active'             => false,
            'is_draft'              => false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $page = Volt::test('pages.rdp.received-documents.record-requests');
        $page->set('officeFilter', $this->targetOffice);
        $page->assertSee($seriesTitle);
        $page->assertSee('Pending');
        $page->assertSee('(For ' . $this->targetOffice . ')');

        $page->call('switchTab', 'submitted');
        $page->assertSee('(Created by ' . $this->targetOffice . ')');

        $page->set('officeFilter', '');
        $page->assertSee('(All Offices)');

        // Clean up
        DB::table('rdp_record')->where('id', $recId)->delete();
        DB::table('rdp_record_series')->where('id', $seriesId)->delete();
    }

    public function test_accept_record_request_activates_and_commits_to_target_office_nap_form_1(): void
    {
        $seriesTitle = 'REQUEST ACCEPT TEST ' . uniqid();
        $desc = 'Acceptable record for ICTU ' . uniqid();

        $seriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title'       => $seriesTitle,
            'recorded_at_office' => $this->targetOffice,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $recId = DB::table('rdp_record')->insertGetId([
            'record_series_id'      => $seriesId,
            'description'           => $desc,
            'volume'                => '3 FOLDERS',
            'is_request'            => true,
            'request_status'        => 'pending',
            'request_origin_office' => $this->originOffice,
            'request_target_office' => $this->targetOffice,
            'office_own'            => $this->targetOffice,
            'is_active'             => false,
            'is_draft'              => false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $page = Volt::test('pages.rdp.received-documents.record-requests');
        $page->call('acceptRequest', $recId);

        // Verify status in DB
        $rec = DB::table('rdp_record')->where('id', $recId)->first();
        $this->assertEquals('accepted', $rec->request_status);
        $this->assertTrue((bool)$rec->is_active);
        $this->assertNotNull($rec->request_actioned_at);

        // Verify origin office received acceptance notification
        $notifTbl = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $originNotifCount = DB::table($notifTbl)->where('office', $this->originOffice)->count();
        $this->assertGreaterThanOrEqual(1, $originNotifCount);

        // Verify the record remains on the Record Requests page with accepted badge
        $page->set('officeFilter', $this->targetOffice);
        $page->assertSee('Accepted');

        // Verify record is active in NAP Form 1 for target office
        $nap1 = Volt::test('pages.rdp.reports.nap-form-1');
        $nap1->set('officeFilter', $this->targetOffice);
        $nap1->set('search', $desc);
        $nap1->assertSee($desc);

        // Clean up
        DB::table('rdp_record')->where('id', $recId)->delete();
        DB::table('rdp_record_series')->where('id', $seriesId)->delete();
    }

    public function test_decline_record_request_leaves_record_inactive_and_notifies_creator(): void
    {
        $seriesTitle = 'REQUEST DECLINE TEST ' . uniqid();
        $desc = 'Declined record for ICTU ' . uniqid();

        $seriesId = DB::table('rdp_record_series')->insertGetId([
            'series_title'       => $seriesTitle,
            'recorded_at_office' => $this->targetOffice,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $recId = DB::table('rdp_record')->insertGetId([
            'record_series_id'      => $seriesId,
            'description'           => $desc,
            'volume'                => '1 BINDER',
            'is_request'            => true,
            'request_status'        => 'pending',
            'request_origin_office' => $this->originOffice,
            'request_target_office' => $this->targetOffice,
            'office_own'            => $this->targetOffice,
            'is_active'             => false,
            'is_draft'              => false,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        $page = Volt::test('pages.rdp.received-documents.record-requests');
        $page->set('actionRecordId', $recId);
        $page->set('declineReason', 'Incorrect office assigned by requester');
        $page->call('declineRequest');

        // Verify in DB
        $rec = DB::table('rdp_record')->where('id', $recId)->first();
        $this->assertEquals('declined', $rec->request_status);
        $this->assertFalse((bool)$rec->is_active);
        $this->assertEquals('Incorrect office assigned by requester', $rec->request_decline_reason);

        // Verify notification to creator office
        $notifTbl = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $originNotif = DB::table($notifTbl)->where('office', $this->originOffice)->latest('id')->first();
        $this->assertNotNull($originNotif);

        // Verify record remains on Record Requests page with Declined badge
        $page->set('officeFilter', $this->targetOffice);
        $page->assertSee('Declined');
        $page->assertSee('Incorrect office assigned by requester');

        // Verify record is NOT active in NAP Form 1 for target office
        $nap1 = Volt::test('pages.rdp.reports.nap-form-1');
        $nap1->set('officeFilter', $this->targetOffice);
        $nap1->set('search', $desc);
        $nap1->assertDontSee($desc);

        // Clean up
        DB::table('rdp_record')->where('id', $recId)->delete();
        DB::table('rdp_record_series')->where('id', $seriesId)->delete();
    }
}
