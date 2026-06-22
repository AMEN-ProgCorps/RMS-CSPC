<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NotificationsTest extends TestCase
{
    private $testUserId = 1;
    private $officeId;
    private $officeCode = 'TEST_OFFICE';
    private $subsystemId;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Authenticate user
        $user = User::find($this->testUserId);
        if (!$user) {
            $this->markTestSkipped('Testing user does not exist.');
            return;
        }
        Auth::login($user);

        // 2. Set up a test office
        $this->officeId = DB::table('office')->insertGetId([
            'office_name' => 'Test Notification Office',
            'office_code' => $this->officeCode,
        ]);

        // Link the authenticated user to this test office
        DB::table('account_details')
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => $this->officeId]);

        // 3. Ensure "Profile Manager" subsystem exists
        $subsystem = DB::table('subsystems')
            ->where('subsystem_name', 'Profile Manager')
            ->first();

        if ($subsystem) {
            $this->subsystemId = $subsystem->subsystem_id;
        } else {
            $this->subsystemId = DB::table('subsystems')->insertGetId([
                'subsystem_name' => 'Profile Manager',
                'subsystem_version' => '1.0.0',
                'created_at' => now(),
                'update_at' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test data
        DB::table('notification_div')->where('account_rec', $this->testUserId)->delete();
        
        $notifications = DB::table('notifications')->where('office', $this->officeCode)->get();
        foreach ($notifications as $notif) {
            DB::table('notifications')->where('id', $notif->id)->delete();
            DB::table('notif_content')->where('id', $notif->contents)->delete();
        }

        DB::table('account_details')
            ->where('account_id', $this->testUserId)
            ->update(['office_id' => null]);

        DB::table('office')->where('id', $this->officeId)->delete();

        parent::tearDown();
    }

    public function test_load_notifications_and_unread_count()
    {
        // 1. Create a dummy notification
        $contentId = DB::table('notif_content')->insertGetId([
            'system' => $this->subsystemId,
            'content' => 'Test notification content',
            'created_at' => now(),
        ]);

        $notificationId = DB::table('notifications')->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId,
            'created_at' => now(),
        ]);

        // 2. Test the Livewire Volt component
        Volt::test('components.notification.notifications')
            ->assertSet('unreadCount', 1)
            ->assertCount('notifications', 1);
    }

    public function test_mark_as_read()
    {
        // 1. Create a dummy notification
        $contentId = DB::table('notif_content')->insertGetId([
            'system' => $this->subsystemId,
            'content' => 'Test mark as read',
            'created_at' => now(),
        ]);

        $notificationId = DB::table('notifications')->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId,
            'created_at' => now(),
        ]);

        // 2. Mark notification as read
        Volt::test('components.notification.notifications')
            ->call('markAsRead', $notificationId)
            ->assertSet('unreadCount', 0);

        // 3. Verify in DB
        $this->assertDatabaseHas('notification_div', [
            'id' => $notificationId,
            'account_rec' => $this->testUserId,
            'status' => 'read'
        ]);
    }

    public function test_mark_all_as_read()
    {
        // 1. Create two dummy notifications
        $contentId1 = DB::table('notif_content')->insertGetId([
            'system' => $this->subsystemId,
            'content' => 'Test mark all 1',
            'created_at' => now(),
        ]);
        $notificationId1 = DB::table('notifications')->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId1,
            'created_at' => now(),
        ]);

        $contentId2 = DB::table('notif_content')->insertGetId([
            'system' => $this->subsystemId,
            'content' => 'Test mark all 2',
            'created_at' => now(),
        ]);
        $notificationId2 = DB::table('notifications')->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId2,
            'created_at' => now(),
        ]);

        // 2. Mark all as read
        Volt::test('components.notification.notifications')
            ->assertSet('unreadCount', 2)
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0);

        // 3. Verify in DB
        $this->assertDatabaseHas('notification_div', [
            'id' => $notificationId1,
            'account_rec' => $this->testUserId,
            'status' => 'read'
        ]);
        $this->assertDatabaseHas('notification_div', [
            'id' => $notificationId2,
            'account_rec' => $this->testUserId,
            'status' => 'read'
        ]);
    }

    public function test_delete_notification()
    {
        // 1. Create a dummy notification
        $contentId = DB::table('notif_content')->insertGetId([
            'system' => $this->subsystemId,
            'content' => 'Test delete notification',
            'created_at' => now(),
        ]);

        $notificationId = DB::table('notifications')->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId,
            'created_at' => now(),
        ]);

        // 2. Delete notification
        Volt::test('components.notification.notifications')
            ->assertCount('notifications', 1)
            ->call('deleteNotification', $notificationId)
            ->assertCount('notifications', 0);

        // 3. Verify in DB
        $this->assertDatabaseHas('notification_div', [
            'id' => $notificationId,
            'account_rec' => $this->testUserId,
            'is_in_user_list' => false
        ]);
    }
}
