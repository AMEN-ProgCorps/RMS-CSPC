<?php

namespace Tests\Feature;

use App\Helpers\SubsystemHelper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The force soft-refresh mechanism: every open tab polls
 * /api/systems/refresh-token and reloads when the subsystem activation
 * token differs from the one its page was rendered with.
 */
class SystemRefreshTokenTest extends TestCase
{
    public function test_refresh_token_changes_when_a_subsystem_is_toggled(): void
    {
        $table = SubsystemHelper::table();
        $this->assertTrue(Schema::hasTable($table));

        $before = SubsystemHelper::refreshToken();
        $this->assertNotSame('', $before);
        $this->assertSame($before, SubsystemHelper::refreshToken(), 'Token must be stable while states are unchanged');

        $original = DB::table($table)->where('subsystem_name', 'Chatify')->value('is_active');
        $this->assertNotNull($original, 'Chatify subsystem row must exist');

        try {
            DB::table($table)->where('subsystem_name', 'Chatify')->update(['is_active' => ! $original]);

            $after = SubsystemHelper::refreshToken();
            $this->assertNotSame('', $after);
            $this->assertNotSame($before, $after, 'Token must change after activate/deactivate');
        } finally {
            DB::table($table)->where('subsystem_name', 'Chatify')->update(['is_active' => $original]);
        }

        $this->assertSame($before, SubsystemHelper::refreshToken(), 'Token must return to its original value after restore');
    }

    public function test_refresh_token_endpoint_requires_authentication(): void
    {
        $this->get('/api/systems/refresh-token')->assertRedirect();
    }

    public function test_refresh_token_endpoint_returns_current_token_for_authenticated_users(): void
    {
        $accTable = Schema::hasTable('sys_account') ? 'sys_account' : 'account';
        $userId = DB::table($accTable)->insertGetId([
            'username'      => 'refresh_token_probe_user',
            'password'      => bcrypt('password'),
            'account_status' => 1,
            'account_role'  => 1,
            'account_active' => true,
            'date_created'  => now(),
            'date_updated'  => now(),
        ]);

        try {
            $user = User::find($userId);
            $this->assertNotNull($user);

            $this->actingAs($user)
                ->get('/api/systems/refresh-token')
                ->assertOk()
                ->assertJson(['token' => SubsystemHelper::refreshToken()]);
        } finally {
            DB::table($accTable)->where('id', $userId)->delete();
        }
    }
}
