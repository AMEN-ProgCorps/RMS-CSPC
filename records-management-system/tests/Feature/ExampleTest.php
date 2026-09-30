<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     *
     * No RefreshDatabase here on purpose: migrate:fresh would wipe the rms_testing
     * clone halfway through a full run (it used to point at the live database).
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
