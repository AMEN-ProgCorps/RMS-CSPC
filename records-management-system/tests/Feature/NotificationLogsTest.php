<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationLogsTest extends TestCase
{
    private $testUserId = 1;
    private $officeId;
    private $officeCode = 'LOG_OFFICE';
    private $subsystemId;
    private $notifContentId;
    private $notificationId;
    private $originalOfficeId;

    private string $tOffice;
    private string $tAccountDetails;
    private string $tSubsystems;
    private string $tNotifContent;
    private string $tNotifications;
    private string $tNotificationDiv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = \Illuminate\Support\Facades\Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccountDetails = \Illuminate\Support\Facades\Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->tSubsystems = \Illuminate\Support\Facades\Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $this->tNotifContent = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $this->tNotifications = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $this->tNotificationDiv = \Illuminate\Support\Facades\Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        // 1. Authenticate user
        $user = User::find($this->testUserId);
        if (!$user) {
            $this->markTestSkipped('Testing user does not exist.');
            return;
        }
        Auth::login($user);

        // Store original office ID to restore later
        $this->originalOfficeId = DB::table($this->tAccountDetails)
            ->where('account_id', $this->testUserId)
            ->value('office_id');

        // 2. Set up a test office
        $this->officeId = DB::table($this->tOffice)->insertGetId([
            'office_name' => 'Log Test Office',
            'office_code' => $this->officeCode,
        ]);

        // Link the authenticated user to this test office
        DB::table($this->tAccountDetails)
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => $this->officeId]);

        // 3. Ensure a test subsystem exists
        $subsystem = DB::table($this->tSubsystems)
            ->where('subsystem_name', 'Profile Manager')
            ->first();

        if ($subsystem) {
            $this->subsystemId = $subsystem->subsystem_id;
        } else {
            $this->subsystemId = DB::table($this->tSubsystems)->insertGetId([
                'subsystem_name' => 'Profile Manager',
                'subsystem_version' => '1.0.0',
                'created_at' => now(),
                'update_at' => now(),
            ]);
        }

        // 4. Create a test notification content
        $this->notifContentId = DB::table($this->tNotifContent)->insertGetId([
            'system' => $this->subsystemId,
            'content' => 'Log test notification message text',
            'created_at' => now(),
        ]);

        // 5. Create a test notification
        $this->notificationId = DB::table($this->tNotifications)->insertGetId([
            'office' => $this->officeCode,
            'contents' => $this->notifContentId,
            'created_at' => now(),
        ]);

        // 6. Create a test notification_div entry
        DB::table($this->tNotificationDiv)->insert([
            'id' => $this->notificationId,
            'account_rec' => $this->testUserId,
            'status' => 'unread',
            'processed_on' => now(),
            'is_in_user_list' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test data
        DB::table($this->tNotificationDiv)->where('id', $this->notificationId)->delete();
        DB::table($this->tNotifications)->where('id', $this->notificationId)->delete();
        DB::table($this->tNotifContent)->where('id', $this->notifContentId)->delete();

        DB::table($this->tAccountDetails)
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => $this->originalOfficeId]);

        DB::table($this->tOffice)->where('id', $this->officeId)->delete();

        parent::tearDown();
    }

    public function test_logs_page_loads_and_displays_notification()
    {
        Volt::test('pages.admin.activity.notifications')
            ->assertSee('Log test notification message text')
            ->assertSee('Log Test Office')
            ->assertSee('Unread');
    }

    public function test_logs_page_search_filter()
    {
        Volt::test('pages.admin.activity.notifications')
            ->set('search', 'Log test notification')
            ->assertSee('Log test notification message text')
            ->set('search', 'nonexistent_search_query_phrase')
            ->assertDontSee('Log test notification message text');
    }

    public function test_logs_page_status_filter()
    {
        // Initially 'unread'
        Volt::test('pages.admin.activity.notifications')
            ->set('statusFilter', 'unread')
            ->assertSee('Log test notification message text')
            ->set('statusFilter', 'read')
            ->assertDontSee('Log test notification message text');

        // Update to 'read'
        DB::table($this->tNotificationDiv)
            ->where('id', $this->notificationId)
            ->update(['status' => 'read']);

        Volt::test('pages.admin.activity.notifications')
            ->set('statusFilter', 'read')
            ->assertSee('Log test notification message text')
            ->set('statusFilter', 'unread')
            ->assertDontSee('Log test notification message text');
    }

    public function test_logs_page_subsystem_filter()
    {
        Volt::test('pages.admin.activity.notifications')
            ->set('subsystemFilter', $this->subsystemId)
            ->assertSee('Log test notification message text')
            ->set('subsystemFilter', 99999) // Invalid subsystem ID
            ->assertDontSee('Log test notification message text');
    }

    public function test_logs_page_visibility_filter()
    {
        // Initially visible/active (is_in_user_list = 1)
        Volt::test('pages.admin.activity.notifications')
            ->set('visibilityFilter', 'active')
            ->assertSee('Log test notification message text')
            ->set('visibilityFilter', 'cleared')
            ->assertDontSee('Log test notification message text');

        // Update to cleared (is_in_user_list = false)
        DB::table($this->tNotificationDiv)
            ->where('id', $this->notificationId)
            ->update(['is_in_user_list' => false]);

        Volt::test('pages.admin.activity.notifications')
            ->set('visibilityFilter', 'cleared')
            ->assertSee('Log test notification message text')
            ->set('visibilityFilter', 'active')
            ->assertDontSee('Log test notification message text');
    }

    public function test_logs_page_clear_filters()
    {
        Volt::test('pages.admin.activity.notifications')
            ->set('search', 'dummy search')
            ->set('statusFilter', 'read')
            ->set('subsystemFilter', 999)
            ->set('visibilityFilter', 'cleared')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('statusFilter', '')
            ->assertSet('subsystemFilter', '')
            ->assertSet('visibilityFilter', '');
    }
}
