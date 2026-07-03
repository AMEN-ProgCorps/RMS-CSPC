<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SystemSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Authenticate admin user (ID 1)
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_settings_page_loads_and_saves()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        // Initialize setting to true
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'page_prewarming_enabled'],
            ['value' => 'true', 'updated_at' => now()]
        );

        // Test component rendering and default value
        $component = Volt::test('pages.admin.settings.index')
            ->assertSet('pagePrewarmingEnabled', true);

        // Modify value and call save
        $component->set('pagePrewarmingEnabled', false)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertSet('successMessage', 'System settings updated successfully!');

        // Assert database was updated
        $this->assertDatabaseHas('system_settings', [
            'key' => 'page_prewarming_enabled',
            'value' => 'false'
        ]);

        // Toggle back and save
        $component->set('pagePrewarmingEnabled', true)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('system_settings', [
            'key' => 'page_prewarming_enabled',
            'value' => 'true'
        ]);
    }
}
