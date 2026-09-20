<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link office-intake DRF/DCN to the controlled registration without using request_id
 * (request_id on intake hides Update/Database). Also backfill existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['dcs_document_request_form', 'dcs_document_change_notice'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'registered_request_id')) {
                    $after = Schema::hasColumn($table, 'rfio_registered_at')
                        ? 'rfio_registered_at'
                        : (Schema::hasColumn($table, 'is_office_intake') ? 'is_office_intake' : null);
                    $col = $blueprint->unsignedInteger('registered_request_id')->nullable();
                    if ($after) {
                        $col->after($after);
                    }
                }
            });
        }

        $this->backfill('dcs_document_request_form', 'doc_title');
        $this->backfill('dcs_document_change_notice', 'document_title');
    }

    public function down(): void
    {
        foreach (['dcs_document_request_form', 'dcs_document_change_notice'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'registered_request_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('registered_request_id');
            });
        }
    }

    private function backfill(string $table, string $titleColumn): void
    {
        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'registered_request_id')
            || ! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        $rows = DB::table($table)
            ->where('is_office_intake', true)
            ->whereNull('registered_request_id')
            ->whereNotNull('rfio_registered_at')
            ->get(['id', $titleColumn . ' as title', 'created_at']);

        foreach ($rows as $row) {
            $title = trim((string) ($row->title ?? ''));
            if ($title === '') {
                continue;
            }

            $mlQuery = DB::table('dcs_masterlist_registration')
                ->where('doc_title', $title)
                ->whereNotNull('request_id')
                ->where('request_id', '>', 0)
                ->orderByDesc('id');

            if (! empty($row->created_at)) {
                $mlQuery->where('created_at', '>=', $row->created_at);
            }

            $requestId = (int) ($mlQuery->value('request_id') ?? 0);
            if ($requestId < 1) {
                continue;
            }

            DB::table($table)->where('id', $row->id)->update([
                'registered_request_id' => $requestId,
                'updated_at' => now(),
            ]);
        }
    }
};
