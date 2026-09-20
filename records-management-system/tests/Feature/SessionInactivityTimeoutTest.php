<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SessionInactivityTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;
    private string $accDetailsTable;
    private string $sysSettingsTable;
    private string $livewireUpdateUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        $this->livewireUpdateUrl = route('default-livewire.update');
        $this->accDetailsTable = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $this->sysSettingsTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';

        $this->user = User::firstOrFail();

        // Ensure default timeout is 15 minutes
        DB::table($this->sysSettingsTable)->updateOrInsert(
            ['key' => 'tab_close_idle_timeout_minutes'],
            ['value' => '15', 'updated_at' => now()]
        );

        // Set initial online status and time
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => Carbon::now()->subMinutes(2),
            ]);
    }

    public function test_active_user_navigation_updates_last_online_time(): void
    {
        $oldTime = Carbon::now()->subMinutes(5);
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => $oldTime,
            ]);

        $response = $this->actingAs($this->user)->get(route('portal'));
        $response->assertOk();

        $updatedTime = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('last_online_time');

        $this->assertTrue(Carbon::parse($updatedTime)->greaterThan($oldTime));
    }

    public function test_active_user_livewire_action_updates_last_online_time(): void
    {
        $oldTime = Carbon::now()->subMinutes(5);
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => $oldTime,
            ]);

        // Livewire request with user action call
        $response = $this->actingAs($this->user)->postJson($this->livewireUpdateUrl, [
            'components' => [
                [
                    'snapshot' => '{}',
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'performSearch', 'params' => []],
                    ],
                ],
            ],
        ], ['X-Livewire' => 'true']);

        $updatedTime = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('last_online_time');

        $this->assertTrue(Carbon::parse($updatedTime)->greaterThan($oldTime));
    }

    public function test_automated_livewire_poll_does_not_update_last_online_time(): void
    {
        $fixedTime = Carbon::now()->subMinutes(5);
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => $fixedTime,
            ]);

        // 1. Pure wire:poll.5s (no calls, no updates)
        $this->actingAs($this->user)->postJson($this->livewireUpdateUrl, [
            'components' => [
                [
                    'snapshot' => '{}',
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ], ['X-Livewire' => 'true']);

        $timeAfterPoll = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('last_online_time');

        $this->assertEquals(
            $fixedTime->toDateTimeString(),
            Carbon::parse($timeAfterPoll)->toDateTimeString()
        );

        // 2. Automated wire:poll="checkRoleUpdate"
        $this->actingAs($this->user)->postJson($this->livewireUpdateUrl, [
            'components' => [
                [
                    'snapshot' => '{}',
                    'updates' => [],
                    'calls' => [
                        ['path' => '', 'method' => 'checkRoleUpdate', 'params' => []],
                    ],
                ],
            ],
        ], ['X-Livewire' => 'true']);

        $timeAfterCheckRole = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('last_online_time');

        $this->assertEquals(
            $fixedTime->toDateTimeString(),
            Carbon::parse($timeAfterCheckRole)->toDateTimeString()
        );
    }

    public function test_chat_unread_count_polling_does_not_update_last_online_time(): void
    {
        $fixedTime = Carbon::now()->subMinutes(5);
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => $fixedTime,
            ]);

        $response = $this->actingAs($this->user)->getJson(route('chat.unread-count'));
        $response->assertOk();

        $timeAfterChatPoll = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('last_online_time');

        $this->assertEquals(
            $fixedTime->toDateTimeString(),
            Carbon::parse($timeAfterChatPoll)->toDateTimeString()
        );
    }

    public function test_inactivity_timeout_triggers_logout_on_background_poll(): void
    {
        // Set user last online to 20 minutes ago (timeout is 15 minutes)
        $staleTime = Carbon::now()->subMinutes(20);
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => $staleTime,
            ]);

        // Background poll arrives
        $response = $this->actingAs($this->user)->postJson($this->livewireUpdateUrl, [
            'components' => [
                [
                    'snapshot' => '{}',
                    'updates' => [],
                    'calls' => [],
                ],
            ],
        ], ['X-Livewire' => 'true']);

        $response->assertStatus(401);
        $response->assertHeader('X-Livewire-Redirect', route('login'));
        $this->assertFalse(Auth::check());

        $isOnline = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('is_currently_online');

        $this->assertFalse((bool) $isOnline);

        $secLogsTable = Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs';
        $log = DB::table($secLogsTable)
            ->where('account', $this->user->id)
            ->whereIn('status', [3, 8])
            ->orderBy('time', 'desc')
            ->first();

        $this->assertNotNull($log);
        $this->assertContains((int) $log->status, [3, 8]);
    }

    public function test_tab_closed_beacon_marks_user_offline(): void
    {
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update(['is_currently_online' => true]);

        $response = $this->actingAs($this->user)->postJson('/api/session/tab-closed');
        $response->assertOk();
        $response->assertJson(['status' => 'closed']);

        $isOnline = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('is_currently_online');

        $this->assertFalse((bool) $isOnline);
    }

    public function test_session_guard_active_heartbeat_updates_last_online_time(): void
    {
        $oldTime = Carbon::now()->subMinutes(10);
        DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->update([
                'is_currently_online' => true,
                'last_online_time' => $oldTime,
            ]);

        $this->actingAs($this->user);

        Volt::test('components.session-guard')
            ->call('activeHeartbeat');

        $updatedTime = DB::table($this->accDetailsTable)
            ->where('account_id', $this->user->id)
            ->value('last_online_time');

        $this->assertTrue(Carbon::parse($updatedTime)->greaterThan($oldTime));
    }

    public function test_session_guard_logout_now_records_security_log(): void
    {
        $this->actingAs($this->user);

        Volt::test('components.session-guard')
            ->call('logoutNow');

        $secLogsTable = Schema::hasTable('sys_security_logs') ? 'sys_security_logs' : 'security_logs';
        $log = DB::table($secLogsTable)
            ->where('account', $this->user->id)
            ->whereIn('status', [3, 8])
            ->orderBy('time', 'desc')
            ->first();

        $this->assertNotNull($log);
        $this->assertContains((int) $log->status, [3, 8]);
    }
}
