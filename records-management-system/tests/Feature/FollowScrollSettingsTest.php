<?php

namespace Tests\Feature;

use App\Models\PersonalSetting;
use App\Models\User;
use Livewire\Volt\Volt;
use Tests\TestCase;

class FollowScrollSettingsTest extends TestCase
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

        PersonalSetting::updateOrCreate(
            ['user' => $this->user->id],
            [
                'follow_scroll_enabled' => false,
                'follow_scroll_side' => true,
                'follow_scroll_vertical' => false,
            ]
        );
    }

    public function test_follow_scroll_defaults_are_correct(): void
    {
        $freshUser = $this->user->fresh();

        $this->assertFalse($freshUser->followScrollEnabled());
        $this->assertTrue($freshUser->followScrollSide());
        $this->assertFalse($freshUser->followScrollVertical());

        Volt::test('pages.profile.settings')
            ->assertSet('followScrollEnabled', false)
            ->assertSet('followScrollSide', true)
            ->assertSet('followScrollVertical', false)
            ->assertSee('Follow Scroll (Edge Auto-Scroll)', false)
            ->assertSee('Side (Horizontal) Auto-Scroll', false)
            ->assertSee('Up / Down (Vertical) Auto-Scroll', false);
    }

    public function test_toggling_follow_scroll_master_persists(): void
    {
        Volt::test('pages.profile.settings')
            ->call('toggleFollowScroll')
            ->assertSet('followScrollEnabled', true);

        $this->assertTrue($this->user->fresh()->followScrollEnabled());

        Volt::test('pages.profile.settings')
            ->call('toggleFollowScroll')
            ->assertSet('followScrollEnabled', false);

        $this->assertFalse($this->user->fresh()->followScrollEnabled());
    }

    public function test_toggling_follow_scroll_side_and_vertical_persists(): void
    {
        Volt::test('pages.profile.settings')
            ->call('toggleFollowScrollSide')
            ->assertSet('followScrollSide', false);

        $this->assertFalse($this->user->fresh()->followScrollSide());

        Volt::test('pages.profile.settings')
            ->call('toggleFollowScrollVertical')
            ->assertSet('followScrollVertical', true);

        $this->assertTrue($this->user->fresh()->followScrollVertical());
    }
}
