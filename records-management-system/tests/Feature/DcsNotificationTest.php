<?php

namespace Tests\Feature;

use App\Services\DcsNotificationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DcsNotificationTest extends TestCase
{
    private string $officeCode = 'TEST_DCS_NOTIF_OFFICE';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('office')->updateOrInsert(
            ['office_code' => $this->officeCode],
            ['office_name' => 'DCS Notification Test Office', 'is_active' => true]
        );
    }

    protected function tearDown(): void
    {
        $notifications = DB::table('notifications')->where('office', $this->officeCode)->get();
        foreach ($notifications as $notif) {
            DB::table('notifications')->where('id', $notif->id)->delete();
            DB::table('notif_content')->where('id', $notif->contents)->delete();
        }

        parent::tearDown();
    }

    public function test_create_notification_tags_document_control_system(): void
    {
        $subsystemId = DB::table('subsystems')
            ->where('subsystem_name', 'Document Control System')
            ->value('subsystem_id');

        $this->assertNotNull($subsystemId);

        DcsNotificationService::createNotification(
            $this->officeCode,
            'Document CSPC-FM-001 has been registered.',
            '/dcs/register'
        );

        $this->assertDatabaseHas('notif_content', [
            'system' => $subsystemId,
            'content' => 'Document CSPC-FM-001 has been registered.',
            'redirect_url' => '/dcs/register',
        ]);

        $this->assertDatabaseHas('notifications', [
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

        $this->assertDatabaseHas('notif_content', [
            'content' => 'Document CSPC-FM-001 (Rev 2) has been registered by Jane Doe.',
            'redirect_url' => '/dcs/register/42/edit',
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

        $this->assertDatabaseHas('notif_content', [
            'content' => 'New Document Request Form DRF-2026-001: Quality Manual was submitted by John Smith and is ready for RFIO processing.',
            'redirect_url' => '/dcs/office/drf/7',
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

        $this->assertDatabaseHas('notif_content', [
            'content' => 'Document CSPC-FM-010 has been stamped (Reference) by Jan Russel.',
            'redirect_url' => '/dcs/stamping?request_id=99',
        ]);
    }
}
