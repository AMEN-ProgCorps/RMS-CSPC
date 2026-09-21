<?php

namespace Tests\Feature;

use App\Services\DcsNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DcsNotificationTest extends TestCase
{
    private string $officeCode = 'TEST_DCS_NOTIF_OFFICE';

    private function officeTable(): string
    {
        return Schema::hasTable('sys_office') ? 'sys_office' : 'office';
    }

    private function notifTable(): string
    {
        return Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
    }

    private function contentTable(): string
    {
        return Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
    }

    private function subsystemTable(): string
    {
        return Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table($this->officeTable())->updateOrInsert(
            ['office_code' => $this->officeCode],
            ['office_name' => 'DCS Notification Test Office', 'is_active' => true]
        );
    }

    protected function tearDown(): void
    {
        $notifications = DB::table($this->notifTable())->where('office', $this->officeCode)->get();
        foreach ($notifications as $notif) {
            DB::table($this->notifTable())->where('id', $notif->id)->delete();
            DB::table($this->contentTable())->where('id', $notif->contents)->delete();
        }

        parent::tearDown();
    }

    public function test_create_notification_tags_document_control_system(): void
    {
        $subsystemId = DB::table($this->subsystemTable())
            ->where('subsystem_name', 'Document Control System')
            ->value('subsystem_id');

        $this->assertNotNull($subsystemId);

        DcsNotificationService::createNotification(
            $this->officeCode,
            'Document CSPC-FM-001 has been registered.',
            '/dcs/register'
        );

        $this->assertDatabaseHas($this->contentTable(), [
            'system' => $subsystemId,
            'content' => 'Document CSPC-FM-001 has been registered.',
            'redirect_url' => '/dcs/register',
        ]);

        $this->assertDatabaseHas($this->notifTable(), [
            'office' => $this->officeCode,
        ]);
    }

    public function test_notify_document_registered_message_and_redirect(): void
    {
        DcsNotificationService::notifyDocumentRegistered(
            $this->officeCode,
            'Jane Doe',
            'CSPC-FM-001',
            42,
            2
        );

        $this->assertDatabaseHas($this->contentTable(), [
            'content' => 'Document CSPC-FM-001 (Rev 2) has been registered by Jane Doe.',
            'redirect_url' => '/dcs/register/42/edit',
        ]);
    }

    public function test_notify_document_distributed_message_and_redirect(): void
    {
        DcsNotificationService::notifyDocumentDistributed(
            $this->officeCode,
            'CSPC-F-COL',
            'Continuation of the Curriculum',
            1
        );

        $this->assertDatabaseHas($this->contentTable(), [
            'content' => 'Document "Continuation of the Curriculum" (CSPC-F-COL, Rev 1) has been registered / controlled and distributed to your office.',
            'redirect_url' => '/dcs/office/documents',
        ]);
    }

    public function test_notify_office_drf_submitted(): void
    {
        DcsNotificationService::notifyOfficeDrfSubmitted(
            $this->officeCode,
            'John Smith',
            'DRF-2026-001',
            'Quality Manual',
            7
        );

        $this->assertDatabaseHas($this->contentTable(), [
            'content' => 'New Document Request Form DRF-2026-001: Quality Manual was submitted by John Smith and is ready for RFIO processing.',
            'redirect_url' => '/dcs/register/requests/drf/7',
        ]);
    }

    public function test_notify_office_intake_ready_for_print_sign(): void
    {
        DcsNotificationService::notifyOfficeIntakeReadyForPrintSign(
            $this->officeCode,
            'drf',
            12,
            'Quality Manual'
        );

        $this->assertDatabaseHas($this->contentTable(), [
            'content' => 'Your Document Request Form "Quality Manual" has been reviewed and is correct. You can now print and sign the request, then bring the signed hard copy to the Records Office for further processing.',
            'redirect_url' => '/dcs/office/drf/12',
        ]);
    }

    public function test_notify_document_stamped(): void
    {
        DcsNotificationService::notifyDocumentStamped(
            $this->officeCode,
            'Jan Russel',
            'CSPC-FM-010',
            99,
            'Reference'
        );

        $this->assertDatabaseHas($this->contentTable(), [
            'content' => 'Document CSPC-FM-010 has been stamped (Reference) by Jan Russel.',
            'redirect_url' => '/dcs/stamping?request_id=99',
        ]);
    }
}
