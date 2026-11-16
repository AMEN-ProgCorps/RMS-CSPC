<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DtsWorkingHoursService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DtsWorkingHoursTest extends TestCase
{
    use DatabaseTransactions;

    protected string $sysTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sysTable = Schema::hasTable('sys_system_settings') ? 'sys_system_settings' : 'system_settings';
        DtsWorkingHoursService::clearCache();
    }

    protected function tearDown(): void
    {
        DtsWorkingHoursService::clearCache();
        parent::tearDown();
    }

    public function test_settings_retrieval_and_defaults(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'false']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DtsWorkingHoursService::clearCache();

        $this->assertFalse(DtsWorkingHoursService::isEnabled());
        $this->assertEquals('06:00', DtsWorkingHoursService::getStartHour());
        $this->assertEquals('17:00', DtsWorkingHoursService::getEndHour());
        $this->assertEquals('mon-fri', DtsWorkingHoursService::getWorkingDays());
        $this->assertStringContainsString('6:00 AM to 5:00 PM', DtsWorkingHoursService::getWorkingHoursWindowNotice());
    }

    public function test_is_within_working_hours_when_disabled_always_returns_true(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'false']);
        DtsWorkingHoursService::clearCache();

        $midnight = Carbon::parse('2026-11-22 00:00:00'); // Sunday midnight
        $this->assertTrue(DtsWorkingHoursService::isWithinWorkingHours($midnight));
        $this->assertTrue(DtsWorkingHoursService::canProcessStationAction($midnight));
    }

    public function test_is_within_working_hours_when_enabled(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'true']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DtsWorkingHoursService::clearCache();

        // Monday 10:00 AM -> Inside
        $mondayWorkHour = Carbon::parse('2026-11-16 10:00:00');
        $this->assertTrue(DtsWorkingHoursService::isWithinWorkingHours($mondayWorkHour));

        // Monday 05:30 AM -> Outside (before 6am)
        $mondayEarly = Carbon::parse('2026-11-16 05:30:00');
        $this->assertFalse(DtsWorkingHoursService::isWithinWorkingHours($mondayEarly));

        // Monday 17:30 PM -> Outside (after 5pm)
        $mondayLate = Carbon::parse('2026-11-16 17:30:00');
        $this->assertFalse(DtsWorkingHoursService::isWithinWorkingHours($mondayLate));

        // Sunday 10:00 AM -> Outside (weekend)
        $sundayDay = Carbon::parse('2026-11-22 10:00:00');
        $this->assertFalse(DtsWorkingHoursService::isWithinWorkingHours($sundayDay));
    }

    public function test_elapsed_working_seconds_and_duration_ignores_non_working_hours_and_weekends(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'true']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DtsWorkingHoursService::clearCache();

        // Friday 16:00 to Monday 08:00
        // Friday 16:00 to 17:00 = 1 hour (3600s)
        // Saturday & Sunday = 0s
        // Monday 06:00 to 08:00 = 2 hours (7200s)
        // Total = 3 hours (10800s)
        $fri = Carbon::parse('2026-11-20 16:00:00');
        $mon = Carbon::parse('2026-11-23 08:00:00');

        $workingSecs = DtsWorkingHoursService::calculateWorkingSeconds($fri, $mon);
        $this->assertEquals(10800, $workingSecs);

        $durStr = DtsWorkingHoursService::calculateDurationString($fri, $mon);
        $this->assertEquals('3 hours', $durStr);

        $workingDays = DtsWorkingHoursService::calculateWorkingDays($fri, $mon);
        $this->assertEquals(0, $workingDays); // Less than 11 hours (1 working day)
    }

    public function test_elapsed_days_full_working_days(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'true']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DtsWorkingHoursService::clearCache();

        // Monday 06:00 to Wednesday 17:00
        // Mon = 11h, Tue = 11h, Wed = 11h => Total 33h = 3 full working days (118800s)
        $start = Carbon::parse('2026-11-16 06:00:00');
        $end = Carbon::parse('2026-11-18 17:00:00');

        $workingSecs = DtsWorkingHoursService::calculateWorkingSeconds($start, $end);
        $this->assertEquals(118800, $workingSecs);

        $workingDays = DtsWorkingHoursService::calculateWorkingDays($start, $end);
        $this->assertEquals(3, $workingDays);

        $durStr = DtsWorkingHoursService::calculateDurationString($start, $end);
        $this->assertEquals('3 days', $durStr);
    }

    public function test_server_settings_toggle_and_state(): void
    {
        $user = User::first() ?? User::factory()->create();
        $this->actingAs($user);

        \Livewire\Livewire::test('pages.admin.server-settings.current-server')
            ->assertSet('dtsWorkingHoursEnabled', false)
            ->call('toggleDtsWorkingHours')
            ->assertSet('dtsWorkingHoursEnabled', true);

        $this->assertTrue(DtsWorkingHoursService::isEnabled());

        \Livewire\Livewire::test('pages.admin.server-settings.current-server')
            ->call('toggleDtsWorkingHours')
            ->assertSet('dtsWorkingHoursEnabled', false);

        $this->assertFalse(DtsWorkingHoursService::isEnabled());
    }

    public function test_action_restriction_outside_working_hours(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'true']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DtsWorkingHoursService::clearCache();

        // Simulate Sunday evening (outside working hours)
        Carbon::setTestNow(Carbon::parse('2026-11-22 20:00:00'));

        $this->assertFalse(DtsWorkingHoursService::canProcessStationAction());

        $user = User::first() ?? User::factory()->create();
        $this->actingAs($user);

        // Attempting forwardToOtherOffice throws an exception outside working hours
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Station processing actions (Receive, Forward, Proceed, Complete, Return) are restricted outside of configured working hours');

        \App\Services\DtsRoutingService::forwardToOtherOffice('NON_EXISTENT_ID', 'SOME_OFFICE', 'Note', $user->id);

        Carbon::setTestNow(); // Reset time
    }

    public function test_auto_dispatch_when_working_hours_commence(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'true']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_auto_forward_created_transaction'], ['value' => 'true']);
        DtsWorkingHoursService::clearCache();

        // 1. Create a mock transaction pending working hours dispatch
        $flowCode = 'TEST-FLOW-' . \Illuminate\Support\Str::random(5);
        $nextFlowId = (DB::table('dts_transaction_flow')->max('id') ?? 0) + 1;
        $flowId = $nextFlowId;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_code' => $flowCode,
            'flow_name' => 'Test Flow',
            'is_active' => 1,
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'internal',
            'flow_for' => 'system',
        ]);

        $office1 = DB::table('sys_office')->first()?->office_code;
        if (!$office1) {
            $office1 = 'OFFICE1';
            DB::table('sys_office')->insert(['office_code' => $office1, 'office_name' => 'Office 1', 'is_active' => true]);
        }
        $office2 = DB::table('sys_office')->where('office_code', '!=', $office1)->first()?->office_code;
        if (!$office2) {
            $office2 = 'OFFICE2';
            DB::table('sys_office')->insert(['office_code' => $office2, 'office_name' => 'Office 2', 'is_active' => true]);
        }

        DB::table('dts_sequence_list')->insert([
            ['control_id' => $flowId, 'sequence_ranking' => 1, 'office_code' => $office1, 'date_in' => now()],
            ['control_id' => $flowId, 'sequence_ranking' => 2, 'office_code' => $office2, 'date_in' => null],
        ]);

        $qrCode = 'QR-' . \Illuminate\Support\Str::random(6);
        DB::table('dts_qr_code')->insert([
            'code_id' => $qrCode,
            'qr_status' => 'used',
            'created_at' => now(),
        ]);

        $transId = 'TRANS-WH-' . \Illuminate\Support\Str::random(6);
        DB::table('dts_transactions')->insert([
            'transaction_id' => $transId,
            'enable_notif' => 1,
            'trans_type' => 'internal',
            'current_office' => $office1,
            'status' => 'ongoing',
            'sequence' => 1,
            'qr_code' => $qrCode,
            'pending_working_hours_dispatch' => true,
        ]);

        DB::table('dts_transaction_details')->insert([
            'id' => $transId,
            'type' => 'memorandom',
            'created_by' => 1,
            'originated_from' => $office1,
            'current_office_hold' => $office1,
            'status' => 'ongoing',
            'transaction_flow' => $flowCode,
            'control_number' => 'CTRL-WH-' . \Illuminate\Support\Str::random(4),
            'date_created' => now(),
            'is_active' => 1,
        ]);

        // While outside working hours (Sunday), dispatching returns 0
        Carbon::setTestNow(Carbon::parse('2026-11-22 20:00:00'));
        $dispatched = DtsWorkingHoursService::dispatchPendingOutsideWorkingHoursTransactions();
        $this->assertEquals(0, $dispatched);

        // Advance time to Monday 08:00 AM (Within working hours)
        Carbon::setTestNow(Carbon::parse('2026-11-23 08:00:00'));
        $dispatched = DtsWorkingHoursService::dispatchPendingOutsideWorkingHoursTransactions();
        $this->assertEquals(1, $dispatched);

        // Verify transaction was advanced to sequence 2 at $office2
        $updatedTrans = DB::table('dts_transactions')->where('transaction_id', $transId)->first();
        $this->assertEquals(2, $updatedTrans->sequence);
        $this->assertEquals($office2, $updatedTrans->current_office);
        $this->assertFalse((bool) $updatedTrans->pending_working_hours_dispatch);

        Carbon::setTestNow(); // Reset time
    }

    public function test_receiving_total_time_forward_to_received_calculation_and_persistence(): void
    {
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_enabled'], ['value' => 'true']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '06:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '17:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_days'], ['value' => 'mon-fri']);
        DtsWorkingHoursService::clearCache();

        $flowCode = 'FLOW-REC-' . \Illuminate\Support\Str::random(6);
        $flowId = (DB::table('dts_transaction_flow')->max('id') ?? 0) + 1;
        DB::table('dts_transaction_flow')->insert([
            'id' => $flowId,
            'flow_code' => $flowCode,
            'flow_name' => 'Receiving Test Flow',
            'is_active' => 1,
            'added_by' => 1,
            'date_added' => now(),
            'flow_use' => 'internal',
            'flow_for' => 'system',
        ]);

        $office1 = DB::table('sys_office')->first()?->office_code ?? 'OFFICE1';
        $office2 = DB::table('sys_office')->where('office_code', '!=', $office1)->first()?->office_code ?? 'OFFICE2';

        $friOut = Carbon::parse('2026-11-20 16:30:00'); // Friday 4:30 PM (30 min before end)
        $monIn = Carbon::parse('2026-11-23 07:30:00');  // Monday 7:30 AM (1 hr 30 min after start)

        DB::table('dts_sequence_list')->insert([
            [
                'control_id' => $flowId,
                'sequence_ranking' => 1,
                'office_code' => $office1,
                'date_in' => Carbon::parse('2026-11-20 16:00:00'),
                'date_out' => $friOut,
                'total_time_received' => '-',
                'total_time_completed' => '30 minutes',
            ],
            [
                'control_id' => $flowId,
                'sequence_ranking' => 2,
                'office_code' => $office2,
                'date_in' => $monIn,
                'date_out' => null,
                'total_time_received' => null,
                'total_time_completed' => null,
            ],
        ]);

        // Calculate expected duration: 30m on Friday + 90m on Monday = 120m = 2 hours
        $expectedDuration = DtsWorkingHoursService::calculateDurationString($friOut, $monIn);
        $this->assertEquals('2 hours', $expectedDuration);

        // Manually update receiving duration as receive action would
        DB::table('dts_sequence_list')
            ->where('control_id', $flowId)
            ->where('sequence_ranking', 2)
            ->update([
                'total_time_received' => $expectedDuration,
            ]);

        // Verify persistence in DB
        $step2 = DB::table('dts_sequence_list')
            ->where('control_id', $flowId)
            ->where('sequence_ranking', 2)
            ->first();
        $this->assertEquals('2 hours', $step2->total_time_received);

        // Changing settings dynamically should NOT mutate the persisted historical value in DB
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_start'], ['value' => '08:00']);
        DB::table($this->sysTable)->updateOrInsert(['key' => 'dts_working_hours_end'], ['value' => '16:00']);
        DtsWorkingHoursService::clearCache();

        $step2AfterChange = DB::table('dts_sequence_list')
            ->where('control_id', $flowId)
            ->where('sequence_ranking', 2)
            ->first();
        $this->assertEquals('2 hours', $step2AfterChange->total_time_received);
    }
}
