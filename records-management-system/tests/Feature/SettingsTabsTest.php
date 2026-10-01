<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * System Settings tab manager: the boxes below the Google SSO card
 * are grouped into category tabs so the page no longer over-scrolls.
 *
 * Note: card titles containing "&" must be asserted with $escape = false,
 * because the rendered HTML keeps the raw ampersand.
 */
class SettingsTabsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_tab_bar_renders_all_categories(): void
    {
        Volt::test('pages.admin.settings.index')
            ->assertSee('General')
            ->assertSee('Document Tracking (DTS)')
            ->assertSee('Records (RDP)')
            ->assertSee('Security');
    }

    public function test_default_tab_shows_only_general_boxes(): void
    {
        Volt::test('pages.admin.settings.index')
            ->assertSet('settingsTab', 'general')
            ->assertSee('System & Performance Controls', false)
            ->assertSee('File Attachment Constraints')
            ->assertDontSee('Document Tracking System (DTS) Settings')
            ->assertDontSee('Session & Inactivity Security Settings', false)
            ->assertDontSee('Rate Limiting & Abuse Prevention', false)
            ->assertDontSee('Records Disposition Program (RDP) Settings');
    }

    public function test_switching_tabs_swaps_the_visible_boxes(): void
    {
        $component = Volt::test('pages.admin.settings.index');

        // DTS tab: only the DTS card
        $component->set('settingsTab', 'dts')
            ->assertSee('Document Tracking System (DTS) Settings')
            ->assertDontSee('System & Performance Controls', false)
            ->assertDontSee('Rate Limiting & Abuse Prevention', false);

        // Security tab: session + rate limiting + email passwords cards
        $component->set('settingsTab', 'security')
            ->assertSee('Session & Inactivity Security Settings', false)
            ->assertSee('Rate Limiting & Abuse Prevention', false)
            ->assertSee('Security & Email Passwords', false)
            ->assertDontSee('System & Performance Controls', false)
            ->assertDontSee('Records Disposition Program (RDP) Settings');

        // RDP tab: RDP card (incl. the Server Default Record Series setting)
        $component->set('settingsTab', 'rdp')
            ->assertSee('Records Disposition Program (RDP) Settings')
            ->assertSee('Server Default Record Series')
            ->assertDontSee('Rate Limiting & Abuse Prevention', false);

        // Back to General
        $component->set('settingsTab', 'general')
            ->assertSee('System & Performance Controls', false)
            ->assertDontSee('Server Default Record Series');
    }

    public function test_settings_values_persist_across_tab_switches(): void
    {
        // Values live in component properties, so a setting edited on one tab
        // survives switching away and is still saved by Save All Settings.
        Volt::test('pages.admin.settings.index')
            ->set('settingsTab', 'security')
            ->set('emailAccessRequiredExternal', false)
            ->set('settingsTab', 'general')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertEquals(
            'false',
            \DB::table('sys_system_settings')->where('key', 'dts_email_access_required_external')->value('value')
        );

        // Restore default for other tests.
        \DB::table('sys_system_settings')->updateOrInsert(
            ['key' => 'dts_email_access_required_external'],
            ['value' => 'true', 'updated_at' => now()]
        );
    }
}
