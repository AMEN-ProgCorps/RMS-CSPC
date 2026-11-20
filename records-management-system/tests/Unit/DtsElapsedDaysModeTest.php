<?php

namespace Tests\Unit;

use App\Services\DtsWorkingHoursService;
use Carbon\Carbon;
use Tests\TestCase;

class DtsElapsedDaysModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DtsWorkingHoursService::clearCache();
    }

    protected function tearDown(): void
    {
        DtsWorkingHoursService::clearCache();
        parent::tearDown();
    }

    public function test_calculate_transaction_elapsed_days_in_total_lifetime_mode(): void
    {
        // 5 days ago created
        $created = Carbon::now()->subDays(5);
        $firstLogIn = Carbon::now()->subDays(5);

        // Current office received 1 day ago
        $currentOfficeLog = (object) [
            'date_in' => Carbon::now()->subDays(1),
            'date_out' => null,
        ];

        // In total_lifetime mode (default without DB override), should measure from 5 days ago
        $days = DtsWorkingHoursService::calculateTransactionElapsedDays($created, $firstLogIn, $currentOfficeLog);
        $this->assertEquals(5, $days);
    }

    public function test_calculate_transaction_elapsed_days_in_current_stage_mode(): void
    {
        // Force current_stage mode via reflection on cached property
        $ref = new \ReflectionProperty(DtsWorkingHoursService::class, 'cachedElapsedDaysMode');
        $ref->setValue(null, 'current_stage');

        // 5 days ago created
        $created = Carbon::now()->subDays(5);
        $firstLogIn = Carbon::now()->subDays(5);

        // Current office received 1 day ago
        $currentOfficeLog = (object) [
            'date_in' => Carbon::now()->subDays(1),
            'date_out' => null,
        ];

        // In current_stage mode, should measure only from 1 day ago
        $days = DtsWorkingHoursService::calculateTransactionElapsedDays($created, $firstLogIn, $currentOfficeLog);
        $this->assertEquals(1, $days);
    }

    public function test_calculate_transaction_elapsed_days_with_null_dates(): void
    {
        $days = DtsWorkingHoursService::calculateTransactionElapsedDays(null, null, null);
        $this->assertEquals(0, $days);
    }
}
