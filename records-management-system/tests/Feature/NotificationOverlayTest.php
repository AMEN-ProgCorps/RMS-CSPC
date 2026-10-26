<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;
use App\Helpers\RegisterQueryHelper;

class NotificationOverlayTest extends TestCase
{
    use DatabaseTransactions;

    private int $userId;
    private int $roleId;
    private int $officeId;
    private string $officeCode = 'TEST_NOTIF_OFFICE';
    private int $subsystemId;

    private string $tOffice;
    private string $tAccount;
    private string $tAccountDetails;
    private string $tSubsystems;
    private string $tNotifContent;
    private string $tNotifications;
    private string $tNotificationDiv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tOffice = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $this->tAccount = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $this->tAccountDetails = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->tSubsystems = Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $this->tNotifContent = Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $this->tNotifications = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $this->tNotificationDiv = Schema::hasTable('sys_notification_div') ? 'sys_notification_div' : 'notification_div';

        $roleTable = Schema::hasTable('sys_condition_key') ? 'sys_condition_key' : 'condition_key';
        $roleDetails = Schema::hasTable('sys_condition_details') ? 'sys_condition_details' : 'condition_details';

        $base = (int) (DB::table($roleTable)->max('id') ?: 100) + 1;
        $this->roleId = $base;

        DB::table($roleDetails)->insert([
            'key_id' => $this->roleId,
            'is_sadm' => true,
            'can_access_dts' => true,
            'can_access_rdp' => true,
            'can_access_dcs' => true,
        ]);

        DB::table($roleTable)->insert([
            'id' => $this->roleId,
            'key_name' => 'Notification Test Role ' . $base,
            'key_description' => 'Test role',
            'modifier_key' => $this->roleId,
            'is_active' => true,
        ]);

        $this->officeId = DB::table($this->tOffice)->insertGetId([
            'office_name' => 'Notification Test Office ' . $base,
            'office_code' => $this->officeCode,
        ]);

        $this->userId = DB::table($this->tAccount)->insertGetId([
            'username' => 'notif_user_' . $base,
            'password' => bcrypt('password'),
            'account_status' => 1,
            'account_role' => $this->roleId,
            'account_active' => true,
            'date_created' => now(),
            'date_updated' => now(),
        ]);

        DB::table($this->tAccountDetails)->insert([
            'account_id' => $this->userId,
            'first_name' => 'Notif',
            'last_name' => 'Tester',
            'office_id' => $this->officeId,
            'email' => 'notif_' . $base . '@example.com',
        ]);

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

        $user = User::find($this->userId);
        Auth::login($user);
    }

    private function createNotification(string $content, ?string $redirectUrl = null, mixed $createdAt = null): int
    {
        $time = $createdAt ?? now();
        $contentId = DB::table($this->tNotifContent)->insertGetId([
            'system' => $this->subsystemId,
            'content' => $content,
            'redirect_url' => $redirectUrl,
            'created_at' => $time,
        ]);

        return DB::table($this->tNotifications)->insertGetId([
            'office' => $this->officeCode,
            'contents' => $contentId,
            'created_at' => $time,
        ]);
    }

    public function test_latest_visible_unread_notification_returns_formatted_data(): void
    {
        $notifId = $this->createNotification('Test overlay notification alert', '/dts?open=123');

        $latest = RegisterQueryHelper::latestVisibleUnreadNotification(
            $this->userId,
            $this->officeCode,
            ['Profile Manager']
        );

        $this->assertNotNull($latest);
        $this->assertEquals($notifId, $latest['id']);
        $this->assertEquals('Test overlay notification alert', $latest['content']);
        $this->assertEquals('/dts?open=123', $latest['redirect_url']);
        $this->assertArrayHasKey('subsystem_name', $latest);
        $this->assertArrayHasKey('time', $latest);
    }

    public function test_chat_unread_count_endpoint_returns_latest_system_notification(): void
    {
        $notifId = $this->createNotification('Ping event notification text', '/profile/settings');

        $user = User::find($this->userId);
        $response = $this->actingAs($user)->getJson(route('chat.unread-count'));

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'unread',
            'chat_unread',
            'system_unread',
            'total_unread',
            'latest_system_notification' => [
                'id',
                'subsystem_name',
                'content',
                'redirect_url',
                'time',
            ],
        ]);

        $this->assertGreaterThan(0, $response->json('system_unread'));
        $this->assertEquals($notifId, $response->json('latest_system_notification.id'));
    }

    public function test_handle_notification_click_marks_notification_as_read(): void
    {
        $notifId = $this->createNotification('Click redirect notification test', '/profile/settings');

        $user = User::find($this->userId);
        Auth::login($user);

        $component = Volt::test('components.notification.notifications');
        $component->call('handleNotificationClick', $notifId);

        $status = DB::table($this->tNotificationDiv)
            ->where('id', $notifId)
            ->where('account_rec', $this->userId)
            ->value('status');

        $this->assertEquals('read', $status);
    }

    public function test_notification_classification_and_filtering(): void
    {
        // 1. Classification
        $this->assertEquals('dts_receiving', \App\Helpers\NotificationFilterHelper::classify('New Transaction CTR-001 is waiting to be received by your office.'));
        $this->assertEquals('dts_received', \App\Helpers\NotificationFilterHelper::classify('Transaction CTR-001 has been received by John.'));
        $this->assertEquals('dts_forwarded', \App\Helpers\NotificationFilterHelper::classify('Transaction CTR-001 has been forwarded by Mary.'));
        $this->assertEquals('dts_completed', \App\Helpers\NotificationFilterHelper::classify('Transaction CTR-001 has been completed, you can now check it.'));
        $this->assertEquals('dcs_distribution', \App\Helpers\NotificationFilterHelper::classify('You have an incoming documented information: "ISO Manual".'));
        $this->assertEquals('dcs_distribution', \App\Helpers\NotificationFilterHelper::classify('Your office has a new controlled document to receive: "ISO Manual".'));
        $this->assertEquals('dcs_registration', \App\Helpers\NotificationFilterHelper::classify('Document DOC-101 has been registered by Registrar.'));

        // 2. Mute helper (flat array and timestamp map)
        $muted = ['dts_receiving', 'dcs_distribution'];
        $this->assertTrue(\App\Helpers\NotificationFilterHelper::isMuted('New Transaction CTR-002 is waiting to be received by your office.', 'Document Tracking System', $muted));
        $this->assertFalse(\App\Helpers\NotificationFilterHelper::isMuted('Transaction CTR-002 has been received by John.', 'Document Tracking System', $muted));

        // Timestamp-aware muting: old notification before mute time is NOT muted
        $timestampMap = ['dts_receiving' => '2026-10-17 12:00:00'];
        $this->assertFalse(\App\Helpers\NotificationFilterHelper::isMuted('New Transaction CTR-002 is waiting to be received by your office.', 'Document Tracking System', $timestampMap, '2026-10-17 11:59:00'));
        $this->assertTrue(\App\Helpers\NotificationFilterHelper::isMuted('New Transaction CTR-002 is waiting to be received by your office.', 'Document Tracking System', $timestampMap, '2026-10-17 12:01:00'));

        // 3. Persist and retrieve
        \App\Helpers\NotificationFilterHelper::setUserMutedTypes($this->userId, ['dts_receiving']);
        $retrieved = \App\Helpers\NotificationFilterHelper::getUserMutedTypes($this->userId);
        $this->assertEquals(['dts_receiving'], $retrieved);
        $retrievedMap = \App\Helpers\NotificationFilterHelper::getUserMutedMap($this->userId);
        $this->assertArrayHasKey('dts_receiving', $retrievedMap);
    }

    public function test_muted_notification_is_excluded_from_unread_ping_count(): void
    {
        $user = User::find($this->userId);

        // Create a receiving transaction notification
        $notifId = $this->createNotification('New Transaction CTR-777 is waiting to be received by your office.', '/dts?open=777');

        // Unmuted: CTR-777 is present as the latest notification
        $unmutedResponse = $this->actingAs($user)->getJson(route('chat.unread-count'));
        $unmutedCount = (int) $unmutedResponse->json('system_unread');
        $this->assertEquals($notifId, $unmutedResponse->json('latest_system_notification.id'));

        // Mute dts_receiving for this user starting before or at current time
        \App\Helpers\NotificationFilterHelper::setUserMutedTypes($this->userId, ['dts_receiving' => now()->subMinute()->toDateTimeString()]);

        $mutedResponse = $this->actingAs($user)->getJson(route('chat.unread-count'));
        $mutedCount = (int) $mutedResponse->json('system_unread');

        // Ping count drops because receiving notifications are filtered out from audio/overlay pings
        $this->assertLessThan($unmutedCount, $mutedCount);
        $this->assertNotEquals($notifId, $mutedResponse->json('latest_system_notification.id'));

        // Total system unread in bell still retains unread notices
        $this->assertGreaterThan(0, (int) $mutedResponse->json('total_system_unread'));
    }

    public function test_notification_manager_renders_muted_status_and_toggles(): void
    {
        // 1. Old notification created 10 minutes ago
        $this->createNotification('New Transaction CTR-OLD is waiting to be received by your office.', '/dts?open=OLD', now()->subMinutes(10));

        // 2. User mutes dts_receiving 5 minutes ago
        \App\Helpers\NotificationFilterHelper::setUserMutedTypes($this->userId, [
            'dts_receiving' => now()->subMinutes(5)->toDateTimeString(),
        ]);

        // 3. New notification created 1 minute ago (after user action)
        $this->createNotification('New Transaction CTR-NEW is waiting to be received by your office.', '/dts?open=NEW', now()->subMinute());

        $user = User::find($this->userId);
        Auth::login($user);

        $component = Volt::test('pages.profile.notification-manager');
        $notifs = $component->get('notifications');

        // Both notifications are listed in history
        $this->assertCount(2, $notifs);

        // Find the new and old notifications
        $newNotif = $notifs->first(fn ($n) => str_contains($n->content, 'CTR-NEW'));
        $oldNotif = $notifs->first(fn ($n) => str_contains($n->content, 'CTR-OLD'));

        // Old notification created before the mute action remains unmuted (unread log visible)
        $this->assertFalse($oldNotif->is_muted);
        $this->assertEquals('unread', $oldNotif->display_status);

        // New notification created after the user mute action is muted
        $this->assertTrue($newNotif->is_muted);
        $this->assertEquals('muted', $newNotif->display_status);

        // Toggle unmute via component
        $component->call('toggleMuteType', 'dts_receiving');
        $this->assertNotContains('dts_receiving', $component->get('mutedTypes'));

        $notifsAfter = $component->get('notifications');
        $newNotifAfter = $notifsAfter->first(fn ($n) => str_contains($n->content, 'CTR-NEW'));
        $this->assertEquals('unread', $newNotifAfter->display_status);

        // Test enableAllAlerts & muteAllAlerts
        $component->call('muteAllAlerts');
        $this->assertNotEmpty($component->get('mutedTypes'));

        $component->call('enableAllAlerts');
        $this->assertEmpty($component->get('mutedTypes'));

        // Test Config button and modal open / close
        $component->assertSee('Config');
        $this->assertFalse($component->get('showConfigModal'));

        $component->call('openConfigModal');
        $this->assertTrue($component->get('showConfigModal'));
        $component->assertSee('Notification Alert Filters &amp; Mute Preferences', false);

        $component->call('closeConfigModal');
        $this->assertFalse($component->get('showConfigModal'));

        // Test saveMutedPreferences
        $component->call('saveMutedPreferences', ['dts_receiving']);
        $this->assertContains('dts_receiving', $component->get('mutedTypes'));
        $this->assertFalse($component->get('showConfigModal'));
    }

    public function test_notification_manager_pagination_limits_to_100_per_page(): void
    {
        $user = User::find($this->userId);
        Auth::login($user);

        // Bulk insert 105 test notifications
        $rows = [];
        for ($i = 1; $i <= 105; $i++) {
            $contentId = DB::table($this->tNotifContent)->insertGetId([
                'system' => $this->subsystemId,
                'content' => "Bulk notification test #{$i}",
                'redirect_url' => '/dts',
                'created_at' => now()->subMinutes(120 - $i),
            ]);
            $rows[] = [
                'office' => $this->officeCode,
                'contents' => $contentId,
                'created_at' => now()->subMinutes(120 - $i),
            ];
        }
        DB::table($this->tNotifications)->insert($rows);

        $component = Volt::test('pages.profile.notification-manager');

        // Check hardcoded PER_PAGE constant is 100
        $this->assertEquals(100, $component->instance()::PER_PAGE);

        // Page 1 should contain exactly 100 items (not 105+)
        $this->assertCount(100, $component->get('notifications'));
        $this->assertEquals(1, $component->get('firstItem'));
        $this->assertEquals(100, $component->get('lastItem'));
        $this->assertGreaterThanOrEqual(105, $component->get('totalFiltered'));
        $this->assertGreaterThanOrEqual(2, $component->get('lastPage'));

        // Navigate to Page 2
        $component->call('gotoPage', 2);
        $this->assertEquals(2, $component->instance()->getPage());
        $this->assertLessThanOrEqual(100, count($component->get('notifications')));
        $this->assertEquals(101, $component->get('firstItem'));

        // Changing filter resets page back to 1
        $component->call('setStatusFilter', 'unread');
        $this->assertEquals(1, $component->instance()->getPage());
        $this->assertEquals(1, $component->get('firstItem'));
    }

    public function test_user_popout_notifications_preference_defaults_to_true_and_can_be_toggled_in_settings(): void
    {
        $user = User::find($this->userId);
        Auth::login($user);

        // 1. By default, popout notifications are enabled
        $this->assertTrue($user->popoutNotifications());

        // 2. Test in Profile Settings page
        $component = Volt::test('pages.profile.settings');
        $component->assertSee('Popout Notification Alerts');
        $this->assertTrue($component->get('enablePopoutNotifications'));

        // 3. Toggle off
        $component->call('toggleEnablePopoutNotifications');
        $component->assertDispatched('rms-popout-setting-changed', enabled: false);
        $this->assertFalse($component->get('enablePopoutNotifications'));
        $this->assertFalse($user->fresh()->popoutNotifications());

        // 4. Toggle back on
        $component->call('toggleEnablePopoutNotifications');
        $component->assertDispatched('rms-popout-setting-changed', enabled: true);
        $this->assertTrue($component->get('enablePopoutNotifications'));
        $this->assertTrue($user->fresh()->popoutNotifications());
    }

    public function test_popout_notification_overlay_is_not_dispatched_when_disabled_by_user(): void
    {
        $user = User::find($this->userId);
        Auth::login($user);

        // Disable popout notifications for this user
        $settingTable = Schema::hasTable('sys_personal_settings') ? 'sys_personal_settings' : 'personal_settings';
        DB::table($settingTable)->updateOrInsert(
            ['user' => $this->userId],
            ['enable_popout_notifications' => false, 'notification_sound_alert' => true]
        );

        $this->assertFalse($user->fresh()->popoutNotifications());

        // Notification component should mount with popoutNotificationsEnabled = false
        $component = Volt::test('components.notification.notifications');
        $this->assertFalse($component->get('popoutNotificationsEnabled'));

        // Notification ping should NOT dispatch overlay
        $component->call('onNotificationPing', [
            'id' => 9999,
            'content' => 'Ping with popout disabled',
            'subsystem_name' => 'Profile Manager',
        ]);
        $component->assertNotDispatched('rms-notification-overlay-show');

        // Dynamically enable popout via event listener
        $component->call('onPopoutSettingChanged', true);
        $this->assertTrue($component->get('popoutNotificationsEnabled'));

        // Now notification ping DOES dispatch overlay
        $component->call('onNotificationPing', [
            'id' => 9999,
            'content' => 'Ping with popout enabled',
            'subsystem_name' => 'Profile Manager',
        ]);
        $component->assertDispatched('rms-notification-overlay-show');
    }

    public function test_portal_page_has_notification_bell_and_subsystem_badges(): void
    {
        $user = User::find($this->userId);
        Auth::login($user);

        // Ensure subsystems exist in DB
        $dtsSubId = DB::table($this->tSubsystems)->where('subsystem_name', 'Document Tracking System')->value('subsystem_id')
            ?? DB::table($this->tSubsystems)->insertGetId([
                'subsystem_name' => 'Document Tracking System',
                'subsystem_version' => '1.0.0',
                'created_at' => now(),
                'update_at' => now(),
            ]);

        $dcsSubId = DB::table($this->tSubsystems)->where('subsystem_name', 'Document Control System')->value('subsystem_id')
            ?? DB::table($this->tSubsystems)->insertGetId([
                'subsystem_name' => 'Document Control System',
                'subsystem_version' => '1.0.0',
                'created_at' => now(),
                'update_at' => now(),
            ]);

        $adminSubId = DB::table($this->tSubsystems)->where('subsystem_name', 'Admin Console')->value('subsystem_id')
            ?? DB::table($this->tSubsystems)->insertGetId([
                'subsystem_name' => 'Admin Console',
                'subsystem_version' => '1.0.0',
                'created_at' => now(),
                'update_at' => now(),
            ]);

        // Insert notifications for DTS and DCS
        $c1 = DB::table($this->tNotifContent)->insertGetId([
            'system' => $dtsSubId,
            'content' => 'DTS Document Transaction Pending',
            'redirect_url' => '/dts',
            'created_at' => now(),
        ]);
        DB::table($this->tNotifications)->insert([
            'office' => $this->officeCode,
            'contents' => $c1,
            'created_at' => now(),
        ]);

        $c2 = DB::table($this->tNotifContent)->insertGetId([
            'system' => $dcsSubId,
            'content' => 'DCS Controlled Document Pending',
            'redirect_url' => '/dcs',
            'created_at' => now(),
        ]);
        DB::table($this->tNotifications)->insert([
            'office' => $this->officeCode,
            'contents' => $c2,
            'created_at' => now(),
        ]);

        // Insert notification for Admin Console (should be EXEMPT from badge)
        $c3 = DB::table($this->tNotifContent)->insertGetId([
            'system' => $adminSubId,
            'content' => 'Admin Console system log notification',
            'redirect_url' => '/admin/console',
            'created_at' => now(),
        ]);
        DB::table($this->tNotifications)->insert([
            'office' => $this->officeCode,
            'contents' => $c3,
            'created_at' => now(),
        ]);

        $portal = Volt::test('pages.portal.access-page');

        // 1. Verify notification bell header actions container exists with desktop-only constraint
        $portal->assertSee('portal-header-actions');
        $portal->assertSee('portal-notif-btn-wrapper desktop-only', false);

        // 2. Verify subsystem unread counts
        $counts = $portal->get('subsystemUnreadCounts');
        $this->assertGreaterThanOrEqual(1, $counts['dts'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $counts['dcs'] ?? 0);

        // 3. Verify EXCEPTIONS: Profile, Admin Console, and Chatify MUST NOT have unread notification badges
        $this->assertEquals(0, $counts['profile'] ?? 0);
        $this->assertEquals(0, $counts['admin'] ?? 0);
        $this->assertEquals(0, $counts['chatify'] ?? 0);

        // 4. Verify badges render in HTML for DTS and DCS
        $portal->assertSee('subsystem-notif-badge');

        // 5. Verify event listener updates subsystem unread counts
        $portal->call('loadSubsystemUnreadCounts');
        $this->assertGreaterThanOrEqual(1, $portal->get('subsystemUnreadCounts')['dts']);
        $this->assertEquals(0, $portal->get('subsystemUnreadCounts')['profile']);
    }
}

