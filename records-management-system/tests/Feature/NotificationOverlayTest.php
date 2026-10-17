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
        $this->assertEquals('dcs_distribution', \App\Helpers\NotificationFilterHelper::classify('Your office has a new controlled document to receive: "ISO Manual".'));
        $this->assertEquals('dcs_registration', \App\Helpers\NotificationFilterHelper::classify('Document DOC-101 has been registered by Registrar.'));

        // 2. Mute helper
        $muted = ['dts_receiving', 'dcs_distribution'];
        $this->assertTrue(\App\Helpers\NotificationFilterHelper::isMuted('New Transaction CTR-002 is waiting to be received by your office.', 'Document Tracking System', $muted));
        $this->assertFalse(\App\Helpers\NotificationFilterHelper::isMuted('Transaction CTR-002 has been received by John.', 'Document Tracking System', $muted));

        // 3. Persist and retrieve
        \App\Helpers\NotificationFilterHelper::setUserMutedTypes($this->userId, ['dts_receiving']);
        $retrieved = \App\Helpers\NotificationFilterHelper::getUserMutedTypes($this->userId);
        $this->assertEquals(['dts_receiving'], $retrieved);
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

        // Mute dts_receiving for this user
        \App\Helpers\NotificationFilterHelper::setUserMutedTypes($this->userId, ['dts_receiving']);

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
        $this->createNotification('New Transaction CTR-888 is waiting to be received by your office.', '/dts?open=888');

        // Set dts_receiving as muted
        \App\Helpers\NotificationFilterHelper::setUserMutedTypes($this->userId, ['dts_receiving']);

        $user = User::find($this->userId);
        Auth::login($user);

        $component = Volt::test('pages.profile.notification-manager');
        $notifs = $component->get('notifications');

        $this->assertCount(1, $notifs);
        $this->assertEquals('muted', $notifs[0]->display_status);
        $this->assertTrue($notifs[0]->is_muted);

        // Toggle unmute via component
        $component->call('toggleMuteType', 'dts_receiving');
        $this->assertNotContains('dts_receiving', $component->get('mutedTypes'));

        $notifsAfter = $component->get('notifications');
        $this->assertEquals('unread', $notifsAfter[0]->display_status);

        // Test enableAllAlerts & muteAllAlerts
        $component->call('muteAllAlerts');
        $this->assertNotEmpty($component->get('mutedTypes'));

        $component->call('enableAllAlerts');
        $this->assertEmpty($component->get('mutedTypes'));
    }
}
