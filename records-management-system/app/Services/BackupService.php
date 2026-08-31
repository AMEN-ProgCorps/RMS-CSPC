<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BackupService
{
    /**
     * Map defining dependency order for table deletion and insertion.
     * Lower number = parent / independent table (inserted first, deleted last).
     * Higher number = child / dependent table (inserted last, deleted first).
     */
    public static function getTablePriorityMap(): array
    {
        return [
            // Tier 1: Lookups & Root Parent Tables
            'subsystems' => 1,
            'subsystem_versions_log' => 2,
            'condition_details' => 3,
            'condition_key' => 4,
            'condition_defaults' => 5,
            'system_settings' => 6,
            'personal_settings' => 7,
            'cluster' => 8,
            'office' => 9,
            'cluster_head' => 10,
            'security_status' => 11,
            'sub_document_tracking_system_logs_types' => 12,
            'document_data' => 13,
            'dts_qr_code' => 14,
            'notif_content' => 15,

            // RDP Reference Tables
            'rdp_record_series_type' => 16,
            'rdp_recorded_value' => 17,
            'rdp_frequence_use' => 18,
            'rdp_restriction_type' => 19,
            'rdp_utility_medium' => 20,
            'rdp_time_value' => 21,
            'rdp_volume_value' => 22,
            'rdp_volume_conversion' => 23,
            'rdp_pending_status' => 24,
            'rdp_record_series' => 25,
            'rdp_record_series_brackets' => 26,
            'folder_data' => 27,
            'main_pending_id' => 28,

            // DCS Catalog Lookups
            'dcs_doc_types' => 29,
            'dcs_version_type' => 30,
            'dcs_originators' => 31,
            'dcs_colleges' => 32,
            'dcs_programs' => 33,
            'dcs_semesters' => 34,
            'dcs_school_years' => 35,
            'dcs_faculties' => 36,
            'dcs_program_courses' => 37,
            'dcs_program_course_faculties' => 38,
            'dcs_checklist_types' => 39,
            'dcs_checklist_version' => 40,
            'dcs_approval_body' => 41,

            // Tier 2: Accounts & User Relations
            'account' => 45,
            'account_details' => 46,
            'tracking_devices_log' => 47,
            'security_logs' => 48,
            'dts_source_office' => 49,

            // Tier 3: Workflow, Hub & Routing
            'dts_transaction_flow' => 50,
            'dts_sequence_list' => 51,
            'hub_flow_datas' => 52,
            'dts_action_options' => 53,
            'dts_email_access' => 54,

            // Tier 4: DTS Transactions & Sub-logs
            'dts_transactions' => 60,
            'dts_transaction_details' => 61,
            'dts_document_trans' => 62,
            'dts_copy_filled_transaction' => 63,
            'dts_copy_filled_to_office' => 64,
            'dts_transaction_version' => 65,
            'dts_requestor_history' => 66,
            'sub_document_tracking_system_logs' => 67,

            // Tier 5: RDP Document Packages
            'rdp_record' => 70,
            'rdp_document_record' => 71,
            'rdp_grouped_record' => 72,
            'rdp_grouped_record_series' => 73,
            'rdp_pending_record' => 74,
            'rdp_pending_record_series' => 75,
            'rdp_period_covered' => 76,
            'rdp_retention_period' => 77,
            'rdp_utility_manager' => 78,
            'rdp_duplication_section' => 79,

            // Tier 6: DCS Requests & Registrations
            'dcs_document_requests' => 85,
            'dcs_approval_records' => 86,
            'dcs_document_change_notice' => 87,
            'dcs_doc_revision' => 88,
            'dcs_document_request_form' => 89,
            'dcs_document_distribution' => 90,
            'dcs_document_retrieval' => 91,
            'dcs_masterlist_registration' => 92,
            'dcs_syllabi' => 93,
            'dcs_syllabi_drf' => 94,
            'dcs_document_stamps' => 95,
            'dcs_opcr_ratings' => 96,
            'dcs_calendar_categories' => 97,
            'dcs_calendar_events' => 98,
            'dcs_report_templates' => 99,
            'dcs_generated_reports' => 100,
            'dcs_drf_offices' => 101,
            'dcs_distribution_offices' => 102,
            'dcs_retrieval_offices' => 103,
            'dcs_dcn_offices' => 104,
            'dcs_masterlist_source_offices' => 105,
            'dcs_masterlist_related_docs' => 106,
            'dcs_syllabi_monitoring_status' => 107,

            // Tier 7: Chatify, Messages & Audit Logs
            'chat_conversations' => 110,
            'chat_messages' => 111,
            'chat_reactions' => 112,
            'chat_read_markers' => 113,
            'chatify_chat_backup' => 114,
            'chat_notifications' => 115,
            'chatify_legal_agreements' => 116,
            'chatify_audit_logs' => 117,
            'notifications' => 120,
            'notification_div' => 121,
            'admin_logs' => 122,
        ];
    }

    /**
     * Restore database from a given JSON backup payload or filename.
     */
    public function restoreBackupFile(string $filename, ?callable $progressCallback = null): array
    {
        $filename = basename($filename);
        $localPath = "backups/{$filename}";
        $googlePath = "backup/{$filename}";

        $jsonContent = null;
        if (Storage::disk('local')->exists($localPath)) {
            $jsonContent = Storage::disk('local')->get($localPath);
        } elseif (Storage::disk('google')->exists($googlePath)) {
            $jsonContent = Storage::disk('google')->get($googlePath);
            try {
                Storage::disk('local')->put($localPath, $jsonContent);
            } catch (\Throwable) {}
        }

        if (!$jsonContent) {
            return [
                'success' => false,
                'message' => "Target backup file [{$filename}] could not be found in storage.",
            ];
        }

        $payload = json_decode($jsonContent, true);
        if (!$payload || !isset($payload['tables']) || !is_array($payload['tables'])) {
            return [
                'success' => false,
                'message' => "Invalid or corrupted JSON content in backup file [{$filename}].",
            ];
        }

        return $this->restorePayload($payload, $filename, $progressCallback);
    }

    /**
     * Atomically restores payload into database.
     */
    public function restorePayload(array $payload, string $filename = 'backup_payload', ?callable $progressCallback = null): array
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');

        $driver = DB::getDriverName();
        $priorityMap = self::getTablePriorityMap();
        $tablesData = $payload['tables'];

        $totalTables = count($tablesData);
        $logCallback = function (string $msg, int $pct = 0) use ($progressCallback) {
            if ($progressCallback) {
                $progressCallback($msg, $pct);
            }
        };

        $logCallback("Beginning atomic database restoration sequence from [{$filename}]...", 2);

        // Sort tables for insertion (lowest priority number = parent first)
        uksort($tablesData, function ($a, $b) use ($priorityMap) {
            $pA = $priorityMap[$a] ?? 60;
            $pB = $priorityMap[$b] ?? 60;
            return $pA <=> $pB;
        });

        // Sort tables for deletion (highest priority number = child first)
        $deletionOrder = array_keys($tablesData);
        usort($deletionOrder, function ($a, $b) use ($priorityMap) {
            $pA = $priorityMap[$a] ?? 60;
            $pB = $priorityMap[$b] ?? 60;
            return $pB <=> $pA;
        });

        try {
            // 1. Disable constraints
            if ($driver === 'pgsql') {
                try {
                    DB::statement("SET session_replication_role = 'replica';");
                } catch (\Throwable) {
                    DB::statement('SET CONSTRAINTS ALL DEFERRED;');
                }
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF;');
            } elseif ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS = 0;');
            }

            $logCallback("Suspended foreign key checks ({$driver}). Clearing existing records in reverse dependency order...", 5);

            // 2. Clear existing records in child-first order
            foreach ($deletionOrder as $tbl) {
                if (in_array($tbl, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])) {
                    continue;
                }
                if (Schema::hasTable($tbl)) {
                    try {
                        DB::table($tbl)->delete();
                    } catch (\Throwable $e) {
                        Log::warning("Could not delete from table {$tbl}: " . $e->getMessage());
                    }
                }
            }

            $logCallback("Database cleared. Restoring tables in forward dependency order...", 15);

            // 3. Insert records table by table
            $restoredCount = 0;
            $tableIndex = 0;

            foreach ($tablesData as $tableName => $rows) {
                $tableIndex++;
                $pct = (int) round(15 + (($tableIndex / max(1, $totalTables)) * 70));

                if (!Schema::hasTable($tableName) || in_array($tableName, ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'failed_jobs'])) {
                    $logCallback("Skipped table [{$tableName}] (not in database schema)", $pct);
                    continue;
                }

                $rowCount = count($rows);
                if ($rowCount > 0) {
                    $dbColumns = array_flip(Schema::getColumnListing($tableName));
                    $chunks = array_chunk($rows, 250);

                    foreach ($chunks as $chunk) {
                        $filteredChunk = [];
                        foreach ($chunk as $row) {
                            $filteredRow = array_intersect_key((array) $row, $dbColumns);
                            if (!empty($filteredRow)) {
                                $filteredChunk[] = $filteredRow;
                            }
                        }
                        if (!empty($filteredChunk)) {
                            DB::table($tableName)->insert($filteredChunk);
                        }
                    }
                }

                $restoredCount += $rowCount;
                $logCallback("Restored [{$tableName}] ({$rowCount} rows)", $pct);
            }

            // 4. Ensure Essential Lookups & Seeds Exist
            $this->ensureEssentialLookups();
            $logCallback("Ensured essential lookups (security_status, condition_key, subsystems)...", 88);

            // 5. PostgreSQL Sequences Resynchronization
            if ($driver === 'pgsql') {
                $this->resyncPgsqlSequences();
                $logCallback("Synchronized PostgreSQL sequence counters...", 95);
            }

            // 6. Re-enable foreign key checks
            if ($driver === 'pgsql') {
                try {
                    DB::statement("SET session_replication_role = 'origin';");
                } catch (\Throwable) {}
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            } elseif ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
            }

            $logCallback("Restoration completed successfully (100%)", 100);

            return [
                'success' => true,
                'message' => "System database successfully restored from [{$filename}] ({$restoredCount} records restored across {$totalTables} tables).",
                'tables_count' => $totalTables,
                'records_count' => $restoredCount,
            ];

        } catch (\Throwable $e) {
            // Attempt to re-enable FK checks on failure
            if ($driver === 'pgsql') {
                try {
                    DB::statement("SET session_replication_role = 'origin';");
                } catch (\Throwable) {}
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            } elseif ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1;');
            }

            Log::error("Backup restore failed: " . $e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => "Restoration failed: " . $e->getMessage(),
            ];
        }
    }

    /**
     * Resynchronize auto-increment sequences in PostgreSQL for all tables.
     */
    public function resyncPgsqlSequences(): void
    {
        try {
            $seqTables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'");
            foreach ($seqTables as $st) {
                $tbl = $st->table_name;
                try {
                    $columns = Schema::getColumnListing($tbl);
                    foreach ($columns as $col) {
                        $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, ?) AS seq", ["\"{$tbl}\"", $col]);
                        $seqName = $seq->seq ?? null;
                        if ($seqName) {
                            $maxId = DB::table($tbl)->max($col) ?: 0;
                            $seqVal = max(1, (int) $maxId);
                            $isCalled = $maxId > 0;
                            DB::statement("SELECT setval(?, ?, ?)", [$seqName, $seqVal, $isCalled]);
                        }
                    }
                } catch (\Throwable) {}
            }
        } catch (\Throwable) {
            // Non-critical sequence log
        }
    }

    /**
     * Safeguard to guarantee default values in critical lookup tables.
     */
    public function ensureEssentialLookups(): void
    {
        // 1. Security Status Lookup Table
        if (Schema::hasTable('security_status')) {
            $statuses = [
                ['status_id' => 1, 'status_name' => 'Login Successful', 'description' => 'User successfully logged into the system.'],
                ['status_id' => 2, 'status_name' => 'Login Failed', 'description' => 'User failed to log into the system.'],
                ['status_id' => 3, 'status_name' => 'Logout', 'description' => 'User logged out from the system.'],
                ['status_id' => 4, 'status_name' => 'Unauthorized Access', 'description' => 'User attempted to access a restricted resource.'],
                ['status_id' => 5, 'status_name' => 'Account Locked', 'description' => 'User account has been locked due to multiple failed attempts.'],
                ['status_id' => 6, 'status_name' => 'Password Reset Requested', 'description' => 'User has requested a password reset.'],
                ['status_id' => 7, 'status_name' => 'Password Reset Successful', 'description' => 'User has successfully reset their password.'],
            ];

            foreach ($statuses as $st) {
                $exists = DB::table('security_status')->where('status_id', $st['status_id'])->exists();
                if (!$exists) {
                    try {
                        DB::table('security_status')->insert(array_merge($st, ['time' => now()]));
                    } catch (\Throwable) {}
                }
            }
        }

        // 2. Condition Details & Condition Keys (Roles)
        if (Schema::hasTable('condition_details') && Schema::hasTable('condition_key')) {
            if (DB::table('condition_details')->count() === 0) {
                try {
                    DB::table('condition_details')->insert([
                        'key_id' => 1,
                        'name' => 'Default Clearance Scope',
                        'date_created' => now(),
                        'date_updated' => now(),
                    ]);
                } catch (\Throwable) {}
            }

            if (Schema::hasTable('account')) {
                $missingRoles = DB::table('account')
                    ->whereNotNull('account_role')
                    ->whereNotIn('account_role', DB::table('condition_key')->pluck('id'))
                    ->pluck('account_role')
                    ->unique();

                $firstModifierKey = DB::table('condition_details')->value('key_id') ?: 1;
                foreach ($missingRoles as $roleId) {
                    try {
                        DB::table('condition_key')->insert([
                            'id' => $roleId,
                            'key_name' => "Role #{$roleId}",
                            'modifier_key' => $firstModifierKey,
                            'date_created' => now(),
                            'date_updated' => now(),
                        ]);
                    } catch (\Throwable) {}
                }
            }
        }

        // 3. Subsystems Lookup Table
        if (Schema::hasTable('subsystems')) {
            $defaultSubsystems = [
                ['subsystem_id' => 1, 'name' => 'Document Tracking System', 'code' => 'DTS', 'active' => 1],
                ['subsystem_id' => 2, 'name' => 'Records Disposition Package', 'code' => 'RDP', 'active' => 1],
                ['subsystem_id' => 3, 'name' => 'Document Control System', 'code' => 'DCS', 'active' => 1],
                ['subsystem_id' => 4, 'name' => 'Chat & Messaging', 'code' => 'CHAT', 'active' => 1],
                ['subsystem_id' => 5, 'name' => 'Admin Console & Logs', 'code' => 'ADMIN', 'active' => 1],
            ];

            foreach ($defaultSubsystems as $sub) {
                $dbCols = Schema::getColumnListing('subsystems');
                $filtered = array_intersect_key($sub, array_flip($dbCols));
                $idCol = in_array('subsystem_id', $dbCols) ? 'subsystem_id' : 'id';
                if (isset($sub[$idCol]) && !DB::table('subsystems')->where($idCol, $sub[$idCol])->exists()) {
                    try {
                        DB::table('subsystems')->insert($filtered);
                    } catch (\Throwable) {}
                }
            }
        }
    }
}
