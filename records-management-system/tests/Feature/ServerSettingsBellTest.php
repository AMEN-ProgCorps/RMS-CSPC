<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ServerSettingsBellTest extends TestCase
{

    public function test_multi_server_renders_without_error(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first();
        if (!$user) {
            $user = User::first();
        }

        $response = $this->actingAs($user)->get('/admin/server-settings/multi-server');
        $response->assertOk();

        // Also test legacy redirect
        $legacy = $this->actingAs($user)->get('/server-settings/adds-on-servers');
        $legacy->assertRedirect('/admin/server-settings/multi-server');
    }

    public function test_current_server_renders_without_error(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first();
        if (!$user) {
            $user = User::first();
        }

        $response = $this->actingAs($user)->get('/admin/server-settings/current-server');
        $response->assertOk();

        // Also test legacy redirect
        $legacy = $this->actingAs($user)->get('/server-settings/current-server');
        $legacy->assertRedirect('/admin/server-settings/current-server');
    }

    public function test_multi_server_can_parse_database_url(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first() ?: User::first();

        \Livewire\Livewire::actingAs($user)
            ->test('pages.admin.server-settings.multi-server')
            ->set('dbUrlInput', 'postgresql://neondb_owner:npg_2Bz6toJZrLEs@ep-muddy-art-axd0zi2q-pooler.c-4.us-east-2.aws.neon.tech/rms?sslmode=require')
            ->call('parseDbUrl')
            ->assertSet('dbHost', 'ep-muddy-art-axd0zi2q-pooler.c-4.us-east-2.aws.neon.tech')
            ->assertSet('dbUser', 'neondb_owner')
            ->assertSet('dbPassword', 'npg_2Bz6toJZrLEs')
            ->assertSet('dbName', 'rms')
            ->assertSet('dbSslMode', 'require');
    }

    public function test_multi_server_db_modal_opens_and_closes(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first() ?: User::first();

        \Livewire\Livewire::actingAs($user)
            ->test('pages.admin.server-settings.multi-server')
            ->assertSet('showDbModal', false)
            ->call('openDbModal')
            ->assertSet('showDbModal', true)
            ->call('closeDbModal')
            ->assertSet('showDbModal', false);
    }

    public function test_multi_server_backup_vm_modal_opens_and_closes(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first() ?: User::first();

        \Livewire\Livewire::actingAs($user)
            ->test('pages.admin.server-settings.multi-server')
            ->assertSet('showBackupVmModal', false)
            ->call('openBackupVmModal')
            ->assertSet('showBackupVmModal', true)
            ->call('closeBackupVmModal')
            ->assertSet('showBackupVmModal', false);
    }

    public function test_multi_server_test_database_connection(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first() ?: User::first();

        $component = \Livewire\Livewire::actingAs($user)
            ->test('pages.admin.server-settings.multi-server')
            ->set('dbUrlInput', 'postgresql://neondb_owner:npg_2Bz6toJZrLEs@ep-muddy-art-axd0zi2q-pooler.c-4.us-east-2.aws.neon.tech/rms?sslmode=require')
            ->call('parseDbUrl')
            ->call('testDatabaseConnection');

        $result = $component->get('testDbResult');
        $this->assertTrue($result['success'] ?? false);
    }

    public function test_multi_server_apply_and_revert_database_config(): void
    {
        $user = User::whereHas('permissions', fn($q) => $q->where('is_sadm', true))->first() ?: User::first();

        $component = \Livewire\Livewire::actingAs($user)
            ->test('pages.admin.server-settings.multi-server')
            ->set('dbUrlInput', 'postgresql://neondb_owner:npg_2Bz6toJZrLEs@ep-muddy-art-axd0zi2q-pooler.c-4.us-east-2.aws.neon.tech/rms?sslmode=require')
            ->call('applyDatabaseConfig');

        $check = $component->get('dbCheck');
        $this->assertTrue($check['valid'] ?? false);
        $this->assertEquals('ep-muddy-art-axd0zi2q-pooler.c-4.us-east-2.aws.neon.tech', $check['host'] ?? '');

        // Now revert to local container
        $component->call('revertToLocalDb');
        $revertCheck = $component->get('dbCheck');
        $this->assertFalse($revertCheck['valid'] ?? true);
        $this->assertEquals('db', $revertCheck['host'] ?? '');
    }
}
