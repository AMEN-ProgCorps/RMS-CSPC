<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Services\DtsNotificationService;
use Illuminate\Support\Facades\DB;

class DtsNotificationTest extends TestCase
{
    private string $officeCode = 'TEST_DTS_NOTIF_OFFICE';
    private string $tNotifications;
    private string $tNotifContent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tNotifications = \Illuminate\Support\Facades\Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $this->tNotifContent = \Illuminate\Support\Facades\Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
    }

    protected function tearDown(): void
    {
        $notifications = DB::table($this->tNotifications)->where('office', $this->officeCode)->get();
        foreach ($notifications as $notif) {
            DB::table($this->tNotifications)->where('id', $notif->id)->delete();
            DB::table($this->tNotifContent)->where('id', $notif->contents)->delete();
        }

        parent::tearDown();
    }

    public function test_notify_waiting_to_be_received()
    {
        DtsNotificationService::notifyWaitingToBeReceived($this->officeCode, 'CTRL-1001', 'TRANS-1001');

        $this->assertDatabaseHas($this->tNotifContent, [
            'content' => 'New Transaction CTRL-1001 is waiting to be received by your office.',
            'redirect_url' => '/dts?open=TRANS-1001',
        ]);

        $this->assertDatabaseHas($this->tNotifications, [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_received()
    {
        DtsNotificationService::notifyReceived($this->officeCode, 'John', 'CTRL-1002', 'TRANS-1002');

        $this->assertDatabaseHas($this->tNotifContent, [
            'content' => 'Transaction CTRL-1002 has been received by John.',
            'redirect_url' => '/dts?open=TRANS-1002',
        ]);

        $this->assertDatabaseHas($this->tNotifications, [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_forwarded()
    {
        DtsNotificationService::notifyForwarded($this->officeCode, 'Jane', 'CTRL-1003', 'TRANS-1003');

        $this->assertDatabaseHas($this->tNotifContent, [
            'content' => 'Transaction CTRL-1003 has been forwarded by Jane.',
            'redirect_url' => '/dts?open=TRANS-1003',
        ]);

        $this->assertDatabaseHas($this->tNotifications, [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_completed()
    {
        DtsNotificationService::notifyCompleted($this->officeCode, 'CTRL-1004', 'TRANS-1004');

        $this->assertDatabaseHas($this->tNotifContent, [
            'content' => 'Transaction CTRL-1004 has been completed, you can now check it.',
            'redirect_url' => '/dts?open=TRANS-1004',
        ]);

        $this->assertDatabaseHas($this->tNotifications, [
            'office' => $this->officeCode,
        ]);
    }
}
