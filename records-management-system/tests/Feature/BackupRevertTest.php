<?php

namespace Tests\Feature;

use Tests\TestCase;
use Livewire\Volt\Volt;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackupRevertTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::find(1);
        if ($admin) {
            Auth::login($admin);
        }
    }

    public function test_revert_backup_handles_foreign_keys_and_table_order_correctly()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        // Generate sample backup payload mimicking user's exact scenario
        $testFilename = 'rms_backup_test_fk_' . date('Y-m-d_His') . '.json';

        $payload = [
            'app_name' => 'RMS CSPC',
            'version' => '1.0',
            'backup_mode' => 'full',
            'categories_included' => ['Users & Accounts', 'Roles, Clearances & System Settings'],
            'created_at' => now()->toIso8601String(),
            'created_by' => 'admin@test.com',
            'tables_count' => 2,
            'total_records' => 2,
            'tables' => [
                // Intentionally put child table 'account' BEFORE parent table 'condition_key'
                'account' => [
                    [
                        'id' => 7777,
                        'username' => 'test_fk_user',
                        'password' => '$2y$12$R/jOS/52cNWCh9cEjZcbWOreTMHDtzX7503h5Ym4021wusgL9Qi9K',
                        'account_role' => 3,
                        'account_status' => 1,
                        'account_active' => 1,
                        'is_chatify_verified' => 1, // Obsolete column present in backup file
                        'date_created' => '2026-08-10 03:05:11',
                        'date_updated' => '2026-08-10 04:05:30',
                    ]
                ],
                'condition_key' => [
                    [
                        'id' => 3,
                        'key_name' => 'Staff Role',
                        'modifier_key' => 1,
                    ],
                    [
                        'id' => 1,
                        'key_name' => 'Admin Role',
                        'modifier_key' => 1,
                    ]
                ]
            ]
        ];

        Storage::disk('local')->put("backups/{$testFilename}", json_encode($payload));

        try {
            Volt::test('pages.admin.backup.index')
                ->set('selectedTargetBackup', $testFilename)
                ->set('backupConfirmInput', 'REVERT')
                ->call('revertToTargetBackup')
                ->assertHasNoErrors()
                ->assertSet('errorMessage', '');

            // Verify account record with account_role=3 restored without FK error
            $this->assertDatabaseHas('account', [
                'id' => 7777,
                'username' => 'test_fk_user',
                'account_role' => 3,
            ]);
        } finally {
            // Cleanup
            Storage::disk('local')->delete("backups/{$testFilename}");
            DB::table('account')->where('id', 7777)->delete();
        }
    }

    public function test_revert_selective_backup_handles_sequences_and_dependencies()
    {
        $admin = User::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin user with ID 1 does not exist in database.');
            return;
        }

        $testFilename = 'rms_backup_test_selective_' . date('Y-m-d_His') . '.json';

        $payload = [
            'app_name' => 'RMS CSPC',
            'version' => '1.0',
            'backup_mode' => 'selective',
            'categories_included' => ['Users & Accounts', 'Offices & Organizational Structure', 'Roles, Clearances & System Settings'],
            'created_at' => now()->toIso8601String(),
            'created_by' => 'admin@test.com',
            'tables_count' => 3,
            'total_records' => 3,
            'tables' => [
                'cluster' => [
                    [
                        'id' => 999,
                        'cluster_name' => 'Test Cluster',
                        'cluster_code' => 'TEST_CLUST',
                        'cluster_head' => 'President',
                        'is_active' => true,
                    ]
                ],
                'office' => [
                    [
                        'id' => 999,
                        'office_name' => 'Test Office Unit',
                        'office_code' => 'TEST_OFFICE',
                        'is_active' => true,
                        'cluster' => 'TEST_CLUST',
                    ]
                ],
                'account' => [
                    [
                        'id' => 8888,
                        'username' => 'test_selective_user',
                        'password' => '$2y$12$R/jOS/52cNWCh9cEjZcbWOreTMHDtzX7503h5Ym4021wusgL9Qi9K',
                        'account_role' => 1,
                        'account_status' => 1,
                        'account_active' => 1,
                        'date_created' => '2026-08-10 03:05:11',
                        'date_updated' => '2026-08-10 04:05:30',
                    ]
                ]
            ]
        ];

        Storage::disk('local')->put("backups/{$testFilename}", json_encode($payload));

        try {
            Volt::test('pages.admin.backup.index')
                ->set('selectedTargetBackup', $testFilename)
                ->set('backupConfirmInput', 'REVERT')
                ->call('revertToTargetBackup')
                ->assertHasNoErrors()
                ->assertSet('errorMessage', '');

            $this->assertDatabaseHas('account', [
                'id' => 8888,
                'username' => 'test_selective_user',
            ]);
            $this->assertDatabaseHas('office', [
                'id' => 999,
                'office_code' => 'TEST_OFFICE',
            ]);
            $this->assertDatabaseHas('cluster', [
                'id' => 999,
                'cluster_code' => 'TEST_CLUST',
            ]);
        } finally {
            Storage::disk('local')->delete("backups/{$testFilename}");
            DB::table('account')->where('id', 8888)->delete();
            DB::table('office')->where('id', 999)->delete();
            DB::table('cluster')->where('id', 999)->delete();
        }
    }
}
