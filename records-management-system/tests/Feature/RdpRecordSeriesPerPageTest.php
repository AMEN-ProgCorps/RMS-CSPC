<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Record Series table: the "Show [10 / 20 / 50] Entries" selector
 * (same idea as the List Transaction pages) drives the table paginator.
 */
class RdpRecordSeriesPerPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_entries_selector_defaults_to_50_and_renders_options(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->assertSet('perPage', 50)
            ->assertSee('Entries')
            ->assertSee('value="10"', false)
            ->assertSee('value="20"', false)
            ->assertSee('value="50"', false)
            ->assertViewHas('records', fn ($records) => $records->perPage() === 50);
    }

    public function test_changing_entries_sizes_the_paginator(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->set('perPage', 10)
            ->assertSet('perPage', 10)
            ->assertViewHas('records', fn ($records) => $records->perPage() === 10 && $records->count() <= 10);

        Volt::test('pages.admin.rdp.record-series')
            ->set('perPage', 20)
            ->assertViewHas('records', fn ($records) => $records->perPage() === 20 && $records->count() <= 20);
    }

    public function test_changing_entries_resets_to_first_page(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->set('paginators', ['page' => 3])
            ->set('perPage', 50)
            ->assertSet('paginators', ['page' => 1]);
    }

    public function test_invalid_entries_value_falls_back_to_default(): void
    {
        Volt::test('pages.admin.rdp.record-series')
            ->set('perPage', 13)
            ->assertViewHas('records', fn ($records) => $records->perPage() === 50);
    }
}
