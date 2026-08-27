<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move legacy DCS audit rows into shared admin_logs (what_system = Document Control System),
 * then drop the dedicated dcs_activity_logs table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dcs_activity_logs') || !Schema::hasTable('admin_logs')) {
            if (Schema::hasTable('dcs_activity_logs')) {
                Schema::drop('dcs_activity_logs');
            }
            return;
        }

        $systemId = (int) DB::table('subsystems')
            ->where('subsystem_name', 'Document Control System')
            ->value('subsystem_id');

        if ($systemId > 0) {
            $rows = DB::table('dcs_activity_logs')->orderBy('id')->get();
            foreach ($rows as $row) {
                $meta = [];
                if (!empty($row->meta)) {
                    $decoded = is_string($row->meta) ? json_decode($row->meta, true) : (array) $row->meta;
                    $meta = is_array($decoded) ? $decoded : [];
                }

                $changes = match ((string) $row->action) {
                    'register_document' => 'Registered document #' . ($row->target_id ?? '?'),
                    'update_document' => 'Updated document #' . ($row->target_id ?? '?'),
                    'delete_document' => 'Deleted document #' . ($row->target_id ?? '?'),
                    'restore_document' => 'Restored document #' . ($row->target_id ?? '?'),
                    'apply_stamp' => 'Applied stamp on request #' . ($row->target_id ?? '?'),
                    'remove_stamp' => 'Removed stamp on request #' . ($row->target_id ?? '?'),
                    default => ucwords(str_replace('_', ' ', (string) $row->action))
                        . (!empty($row->target_id) ? ' #' . $row->target_id : ''),
                };

                if (!empty($meta['doc_no'])) {
                    $changes .= ' — ' . $meta['doc_no'];
                }
                if (isset($meta['revise_no'])) {
                    $changes .= ' (Rev ' . $meta['revise_no'] . ')';
                }
                if (!empty($meta['doc_title'])) {
                    $changes .= ': ' . $meta['doc_title'];
                }
                if (!empty($meta['stamp_type'])) {
                    $changes .= ' (' . $meta['stamp_type']
                        . (!empty($meta['file_key']) ? ', ' . $meta['file_key'] : '')
                        . ')';
                } elseif (!empty($meta['file_key'])) {
                    $changes .= ' — ' . $meta['file_key'];
                }

                DB::table('admin_logs')->insert([
                    'changes' => $changes,
                    'admin_id' => (int) $row->account_id,
                    'what_system' => $systemId,
                    'when_changes' => $row->created_at ?? now(),
                ]);
            }
        }

        Schema::drop('dcs_activity_logs');
    }

    public function down(): void
    {
        // Intentionally not recreating dcs_activity_logs — DCS now uses admin_logs.
    }
};
