<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationBoxRemodelTest extends TestCase
{
    private int $testUserId = 1;
    private int $officeId;
    private string $officeCode = 'TEST_REMODEL_OFFICE';
    private int $subsystemId;
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

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->tSubsystems = Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $this->tNotifContent = Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $this->tNotifications = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $this->tNotificationDiv = Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        $user = User::find($this->testUserId);
        if (!$user) {
            $this->markTestSkipped('Test user does not exist.');
            return;
        }
        Auth::login($user);

        // Store original office ID to restore later
        $this->originalOfficeId = DB::table($this->tAccountDetails)
            ->where('account_id', $this->testUserId)
            ->value('office_id');

        // Create test office
        $this->officeId = DB::table($this->tOffice)->insertGetId([
            'office_name' => 'Remodel Test Office',
            'office_code' => $this->officeCode,
        ]);

        DB::table($this->tAccountDetails)
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => $this->officeId]);

        // Ensure Profile Manager subsystem exists
        $subsystem = DB::table($this->tSubsystems)->where('subsystem_name', 'Profile Manager')->first();
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
    }

    protected function tearDown(): void
    {
        $testNotifIds = DB::table($this->tNotifications)->where('office', $this->officeCode)->pluck('id');
        DB::table($this->tNotificationDiv)->whereIn('id', $testNotifIds)->delete();

        $notifications = DB::table($this->tNotifications)->where('office', $this->officeCode)->get();
        foreach ($notifications as $notif) {
            DB::table($this->tNotifications)->where('id', $notif->id)->delete();
            DB::table($this->tNotifContent)->where('id', $notif->contents)->delete();
        }

        DB::table($this->tAccountDetails)
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => $this->originalOfficeId]);

        DB::table($this->tOffice)->where('id', $this->officeId)->delete();

        parent::tearDown();
    }

    private function createNotification(string $content, ?string $redirectUrl = null): int
    {
        $contentId = DB::table($this->tNotifContent)->insertGetId([
            'system' => $this->subsystemId,
            'content' => $content,
            'redirect_url' => $redirectUrl,
            'created_at' => now(),
        ]);

        return DB::table($this->tNotifications)->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId,
            'created_at' => now(),
        ]);
    }

    /**
     * 1. Empty state in notification box displays specified string
     */
    public function test_empty_notification_box_displays_current_session_text()
    {
        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 0)
            ->assertSee('No notification currently at this session..');
    }

    /**
     * 2. Unread notifications appear and are counted
     */
    public function test_unread_notification_appears_in_dropdown()
    {
        $id = $this->createNotification('Unread notification test');

        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 1)
            ->assertSet('unreadCount', 1)
            ->assertSee('Unread notification test');
    }

    /**
     * 3. Read notification in the current session remains visible
     */
    public function test_read_notification_in_current_session_remains_visible()
    {
        $id = $this->createNotification('Read in session test');

        // Mark as read in current session
        DB::table($this->tNotificationDiv)->insert([
            'id' => $id,
            'account_rec' => $this->testUserId,
            'status' => 'read',
            'read_at_session' => session()->getId(),
            'processed_on' => now(),
        ]);

        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 1)
            ->assertSet('unreadCount', 0)
            ->assertSee('Read in session test');
    }

    /**
     * 4. Read notification from a previous session is excluded in a new session
     */
    public function test_read_notification_from_past_session_is_hidden()
    {
        $id = $this->createNotification('Past session notification');

        // Marked as read in an old session
        DB::table($this->tNotificationDiv)->insert([
            'id' => $id,
            'account_rec' => $this->testUserId,
            'status' => 'read',
            'read_at_session' => 'old_session_abc123',
            'processed_on' => now(),
        ]);

        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 0)
            ->assertSee('No notification currently at this session..');
    }

    /**
     * 5. "Mark all as read" button threshold: hidden when < 2 unread, visible when >= 2 unread
     */
    public function test_mark_all_as_read_button_visibility_threshold()
    {
        // 1 unread notification: Mark all as read button should NOT be rendered
        $id1 = $this->createNotification('First notification');

        Volt::test('components.notification.notifications')
            ->assertSet('unreadCount', 1)
            ->assertDontSee('Mark all as read')
            ->assertSee('Clear all notification');

        // 2 unread notifications: Mark all as read button SHOULD be rendered
        $id2 = $this->createNotification('Second notification');

        Volt::test('components.notification.notifications')
            ->assertSet('unreadCount', 2)
            ->assertSee('Mark all as read')
            ->assertSee('Clear all notification');
    }

    /**
     * 6. "Mark all as read" sets status to read and associates active session ID
     */
    public function test_mark_all_as_read_action_updates_session_and_status()
    {
        $id1 = $this->createNotification('Notice A');
        $id2 = $this->createNotification('Notice B');

        Volt::test('components.notification.notifications')
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0)
            ->assertCount('notifications', 2);

        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id1,
            'account_rec' => $this->testUserId,
            'status' => 'read',
            'read_at_session' => session()->getId(),
        ]);

        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id2,
            'account_rec' => $this->testUserId,
            'status' => 'read',
            'read_at_session' => session()->getId(),
        ]);
    }

    /**
     * 7. Single clear sets is_dismissed = true and excludes item from dropdown, no Delete option
     */
    public function test_clear_single_notification()
    {
        $id = $this->createNotification('Clear me');

        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 1)
            ->assertSee('Clear')
            ->assertDontSee('Delete')
            ->call('clearNotification', $id)
            ->assertCount('notifications', 0);

        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id,
            'account_rec' => $this->testUserId,
            'is_dismissed' => true,
            'is_in_user_list' => true,
        ]);
    }

    public function test_dismiss_single_notification()
    {
        $id = $this->createNotification('Dismiss me');

        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 1)
            ->call('dismissNotification', $id)
            ->assertCount('notifications', 0);

        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id,
            'account_rec' => $this->testUserId,
            'is_dismissed' => true,
            'is_in_user_list' => true,
        ]);
    }

    /**
     * 8. "Clear all notification" mass dismisses all visible items
     */
    public function test_clear_all_notifications_mass_dismisses_visible_items()
    {
        $id1 = $this->createNotification('Mass item 1');
        $id2 = $this->createNotification('Mass item 2');

        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 2)
            ->call('clearAllNotifications')
            ->assertCount('notifications', 0)
            ->assertSee('No notification currently at this session..');

        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id1,
            'account_rec' => $this->testUserId,
            'is_dismissed' => true,
        ]);

        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id2,
            'account_rec' => $this->testUserId,
            'is_dismissed' => true,
        ]);
    }

    /**
     * 9. Profile Notification Manager table columns and actions
     */
    public function test_profile_notification_manager_columns_and_modal()
    {
        $id = $this->createNotification('Profile manager test item', '/dts?open=123');

        $test = Volt::test('pages.profile.notification-manager')
            ->assertCount('notifications', 1)
            ->assertSee('Message')
            ->assertSee('Status')
            ->assertSee('Subsystem')
            ->assertSee('Received At')
            ->assertSee('Action')
            ->assertSee('Profile manager test item');

        // Test opening More Details modal
        $test->call('openDetailsModal', $id)
            ->assertSet('showDetailsModal', true)
            ->assertSet('selectedDetails.id', $id)
            ->assertSet('selectedDetails.content', 'Profile manager test item')
            ->assertSet('selectedDetails.redirect_url', '/dts?open=123')
            ->assertSee('Notification #' . $id)
            ->assertSee('@keydown.escape.window="$wire.closeDetailsModal()"', false);

        // Test closing modal
        $test->call('closeDetailsModal')
            ->assertSet('showDetailsModal', false)
            ->assertSet('selectedDetails', null);

        // Test markAsRead from profile page
        $test->call('markAsRead', $id);
        $this->assertDatabaseHas($this->tNotificationDiv, [
            'id' => $id,
            'account_rec' => $this->testUserId,
            'status' => 'read',
            'read_at_session' => session()->getId(),
        ]);
    }
}
