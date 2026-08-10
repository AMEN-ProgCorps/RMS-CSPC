<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\DtsNotificationService;
use Illuminate\Support\Facades\DB;

class DtsNotificationTest extends TestCase
{
    private string $officeCode = 'TEST_DTS_NOTIF_OFFICE';

    protected function tearDown(): void
    {
        $notifications = DB::table('notifications')->where('office', $this->officeCode)->get();
        foreach ($notifications as $notif) {
            DB::table('notifications')->where('id', $notif->id)->delete();
            DB::table('notif_content')->where('id', $notif->contents)->delete();
        }

        parent::tearDown();
    }

    public function test_notify_waiting_to_be_received()
    {
        DtsNotificationService::notifyWaitingToBeReceived($this->officeCode, 'CTRL-1001', 'TRANS-1001');

        $this->assertDatabaseHas('notif_content', [
            'content' => 'New Transaction CTRL-1001 is waiting to be received by your office.',
            'redirect_url' => '/dts?open=TRANS-1001',
        ]);

        $this->assertDatabaseHas('notifications', [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_received()
    {
        DtsNotificationService::notifyReceived($this->officeCode, 'John', 'CTRL-1002', 'TRANS-1002');

        $this->assertDatabaseHas('notif_content', [
            'content' => 'Transaction CTRL-1002 has been received by John.',
            'redirect_url' => '/dts?open=TRANS-1002',
        ]);

        $this->assertDatabaseHas('notifications', [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_forwarded()
    {
        DtsNotificationService::notifyForwarded($this->officeCode, 'Jane', 'CTRL-1003', 'TRANS-1003');

        $this->assertDatabaseHas('notif_content', [
            'content' => 'Transaction CTRL-1003 has been forwarded by Jane.',
            'redirect_url' => '/dts?open=TRANS-1003',
        ]);

        $this->assertDatabaseHas('notifications', [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_completed()
    {
        DtsNotificationService::notifyCompleted($this->officeCode, 'CTRL-1004', 'TRANS-1004');

        $this->assertDatabaseHas('notif_content', [
            'content' => 'Transaction CTRL-1004 has been completed, you can now check it.',
            'redirect_url' => '/dts?open=TRANS-1004',
        ]);

        $this->assertDatabaseHas('notifications', [
            'office' => $this->officeCode,
        ]);
    }
}
