<?php

namespace Tests\Feature;

use App\Models\PersonalSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UrgentNoticeSettingsAndTonesTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::first();
        if (!$user) {
            $user = User::factory()->create();
        }
        $this->user = $user;
        $this->actingAs($this->user);
    }

    public function test_user_sound_and_tone_helpers_with_no_personal_settings_return_defaults(): void
    {
        // Delete any personal setting record for this user to test fallback defaults
        PersonalSetting::where('user', $this->user->id)->delete();
        $freshUser = $this->user->fresh();

        $this->assertTrue($freshUser->notificationSoundAlert());
        $this->assertTrue($freshUser->urgentSoundAlert());
        $this->assertSame('ping-default', $freshUser->notificationSoundTone());
        $this->assertSame('urgent-alarm', $freshUser->urgentSoundTone());
    }

    public function test_personal_setting_model_casts_and_fillables(): void
    {
        $setting = PersonalSetting::updateOrCreate(
            ['user' => $this->user->id],
            [
                'urgent_sound_alert' => false,
                'notification_sound_tone' => 'chime-soft',
                'urgent_sound_tone' => 'warning-beep',
            ]
        );

        $this->assertFalse($setting->urgent_sound_alert);
        $this->assertIsBool($setting->urgent_sound_alert);
        $this->assertSame('chime-soft', $setting->notification_sound_tone);
        $this->assertSame('warning-beep', $setting->urgent_sound_tone);

        $freshUser = $this->user->fresh();
        $this->assertFalse($freshUser->urgentSoundAlert());
        $this->assertSame('chime-soft', $freshUser->notificationSoundTone());
        $this->assertSame('warning-beep', $freshUser->urgentSoundTone());
    }

    public function test_profile_settings_toggles_and_tone_setters_with_event_dispatches(): void
    {
        // Reset personal settings
        PersonalSetting::updateOrCreate(
            ['user' => $this->user->id],
            [
                'urgent_sound_alert' => true,
                'urgent_sound_tone' => 'urgent-alarm',
                'notification_sound_tone' => 'ping-default',
            ]
        );

        Volt::test('pages.profile.settings')
            ->assertSet('urgentSoundAlert', true)
            ->assertSet('urgentSoundTone', 'urgent-alarm')
            ->assertSet('notificationSoundTone', 'ping-default')
            // Toggle urgent sound alert off
            ->call('toggleUrgentSoundAlert')
            ->assertSet('urgentSoundAlert', false)
            ->assertDispatched('rms-urgent-sound-setting-changed', enabled: false)
            ->assertDispatched('rms-settings-changed')
            // Toggle urgent sound alert back on
            ->call('toggleUrgentSoundAlert')
            ->assertSet('urgentSoundAlert', true)
            ->assertDispatched('rms-urgent-sound-setting-changed', enabled: true)
            // Set valid notification tones
            ->call('setNotificationSoundTone', 'bell-ding')
            ->assertSet('notificationSoundTone', 'bell-ding')
            ->assertDispatched('rms-notif-tone-changed', tone: 'bell-ding')
            // Invalid notification tone falls back to ping-default
            ->call('setNotificationSoundTone', 'invalid-notif-tone')
            ->assertSet('notificationSoundTone', 'ping-default')
            ->assertDispatched('rms-notif-tone-changed', tone: 'ping-default')
            // Set valid urgent sound tones
            ->call('setUrgentSoundTone', 'radar-pulse')
            ->assertSet('urgentSoundTone', 'radar-pulse')
            ->assertDispatched('rms-urgent-tone-changed', tone: 'radar-pulse')
            // Invalid urgent tone falls back to urgent-alarm
            ->call('setUrgentSoundTone', 'invalid-urgent-tone')
            ->assertSet('urgentSoundTone', 'urgent-alarm')
            ->assertDispatched('rms-urgent-tone-changed', tone: 'urgent-alarm');

        // Verify DB persistence
        $freshSetting = PersonalSetting::where('user', $this->user->id)->first();
        $this->assertTrue($freshSetting->urgent_sound_alert);
        $this->assertSame('ping-default', $freshSetting->notification_sound_tone);
        $this->assertSame('urgent-alarm', $freshSetting->urgent_sound_tone);
    }

    public function test_admin_settings_loads_and_saves_urgent_settings_with_boundaries(): void
    {
        $sysTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';

        DB::table($sysTable)->updateOrInsert(
            ['key' => 'dts_urgent_monitoring_enabled'],
            ['value' => 'true', 'updated_at' => now()]
        );
        DB::table($sysTable)->updateOrInsert(
            ['key' => 'dts_urgent_warning_minutes'],
            ['value' => '5', 'updated_at' => now()]
        );
        DB::table($sysTable)->updateOrInsert(
            ['key' => 'dts_urgent_action_minutes'],
            ['value' => '10', 'updated_at' => now()]
        );

        $component = Volt::test('pages.admin.settings.index')
            ->assertSet('dtsUrgentMonitoringEnabled', true)
            ->assertSet('dtsUrgentWarningMinutes', 5)
            ->assertSet('dtsUrgentActionMinutes', 10);

        // Update with custom values
        $component->set('dtsUrgentMonitoringEnabled', false)
            ->set('dtsUrgentWarningMinutes', 8)
            ->set('dtsUrgentActionMinutes', 15)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas($sysTable, [
            'key' => 'dts_urgent_monitoring_enabled',
            'value' => 'false',
        ]);
        $this->assertDatabaseHas($sysTable, [
            'key' => 'dts_urgent_warning_minutes',
            'value' => '8',
        ]);
        $this->assertDatabaseHas($sysTable, [
            'key' => 'dts_urgent_action_minutes',
            'value' => '15',
        ]);

        // Test boundary clamping with 0 and negative values
        $component->set('dtsUrgentWarningMinutes', 0)
            ->set('dtsUrgentActionMinutes', -5)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas($sysTable, [
            'key' => 'dts_urgent_warning_minutes',
            'value' => '1',
        ]);
        $this->assertDatabaseHas($sysTable, [
            'key' => 'dts_urgent_action_minutes',
            'value' => '1',
        ]);
    }
}
