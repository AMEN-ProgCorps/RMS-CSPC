<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationSampleDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subsystemsTbl = Schema::hasTable('sys_subsystems') ? 'sys_subsystems' : 'subsystems';
        $notifContentTbl = Schema::hasTable('sys_notif_content') ? 'sys_notif_content' : 'notif_content';
        $notifTbl = Schema::hasTable('sys_notifications') ? 'sys_notifications' : 'notifications';
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';

        // Retrieve subsystem IDs
        $subsystems = DB::table($subsystemsTbl)->pluck('subsystem_id', 'subsystem_name')->toArray();

        $dtsSubId = $subsystems['Document Tracking System'] ?? null;
        $dcsSubId = $subsystems['Document Control System'] ?? null;
        $rdpSubId = $subsystems['Records Disposition Program'] ?? null;
        $profileSubId = $subsystems['Profile Manager'] ?? ($subsystems['Profile'] ?? null);
        $adminSubId = $subsystems['Admin Console'] ?? null;

        // Retrieve all offices
        $officeCodes = DB::table($officeTbl)->whereNotNull('office_code')->pluck('office_code')->toArray();
        if (empty($officeCodes)) {
            $officeCodes = ['RFOIU', 'ICTU', 'PRES', 'VPAA', 'VPAF', 'REG'];
        }

        $sampleNotifications = [
            // DTS Notifications
            [
                'system_id' => $dtsSubId,
                'content' => 'New Transaction CTR-2026-0104 is waiting to be received by your office.',
                'redirect_url' => '/dts/receive',
                'created_at' => now()->subMinutes(8),
            ],
            [
                'system_id' => $dtsSubId,
                'content' => 'Transaction CTR-2026-0098 has been forwarded to your office with remarks: For review and signature.',
                'redirect_url' => '/dts',
                'created_at' => now()->subMinutes(35),
            ],
            [
                'system_id' => $dtsSubId,
                'content' => 'Transaction CTR-2026-0085: Revision completed by originating office, ready for verification.',
                'redirect_url' => '/dts',
                'created_at' => now()->subHours(2),
            ],

            // DCS Notifications
            [
                'system_id' => $dcsSubId,
                'content' => 'Your office has a new controlled document to receive: "Quality Objectives and Policies Manual 2026".',
                'redirect_url' => '/dcs/register',
                'created_at' => now()->subMinutes(18),
            ],
            [
                'system_id' => $dcsSubId,
                'content' => 'Controlled Document SOP-ICT-002 (System Operations Guide) has been published and distributed.',
                'redirect_url' => '/dcs',
                'created_at' => now()->subHours(3),
            ],

            // RDP Notifications
            [
                'system_id' => $rdpSubId,
                'content' => 'Annual Records Inventory and Appraisal review schedule has been initiated for your office.',
                'redirect_url' => '/rdp',
                'created_at' => now()->subMinutes(45),
            ],
            [
                'system_id' => $rdpSubId,
                'content' => 'Records Disposition Schedule RDS-2026-B is pending evaluation and approval.',
                'redirect_url' => '/rdp',
                'created_at' => now()->subHours(5),
            ],

            // Profile Notifications (exempt from badge, but appears in notification bell & center)
            [
                'system_id' => $profileSubId,
                'content' => 'Security Notice: Please review your recent account login activity in Security Logs.',
                'redirect_url' => '/profile/security-logs',
                'created_at' => now()->subHours(1),
            ],

            // Admin Console Notifications (exempt from badge)
            [
                'system_id' => $adminSubId,
                'content' => 'System Backup & Health Audit completed successfully across all cluster nodes.',
                'redirect_url' => '/admin/console',
                'created_at' => now()->subHours(4),
            ],
        ];

        foreach ($officeCodes as $code) {
            foreach ($sampleNotifications as $item) {
                if (! $item['system_id']) {
                    continue;
                }

                // Check if identical content exists for this office recently to avoid duplicates
                $contentId = DB::table($notifContentTbl)
                    ->where('system', $item['system_id'])
                    ->where('content', $item['content'])
                    ->value('id');

                if (! $contentId) {
                    $contentId = DB::table($notifContentTbl)->insertGetId([
                        'system' => $item['system_id'],
                        'content' => $item['content'],
                        'redirect_url' => $item['redirect_url'],
                        'created_at' => $item['created_at'],
                    ]);
                }

                $exists = DB::table($notifTbl)
                    ->where('office', $code)
                    ->where('contents', $contentId)
                    ->exists();

                if (! $exists) {
                    DB::table($notifTbl)->insert([
                        'office' => $code,
                        'contents' => $contentId,
                        'created_at' => $item['created_at'],
                    ]);
                }
            }
        }

        $this->command?->info('Sample notifications seeded successfully for all offices.');
    }
}
