<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ServerSettingsBellTest extends TestCase
{

    public function test_adds_on_servers_renders_without_error(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first();
        if (!$user) {
            $user = User::first();
        }

        $response = $this->actingAs($user)->get('/server-settings/adds-on-servers');
        $response->assertOk();
    }

    public function test_current_server_renders_without_error(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first();
        if (!$user) {
            $user = User::first();
        }

        $response = $this->actingAs($user)->get('/server-settings/current-server');
        $response->assertOk();
    }
}
