<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return;
        }

        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_distribution_offices', 'distribution_status')) {
                $table->string('distribution_status', 32)->default('pending_pickup')->after('office_received_by');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'copy_retrieval_status')) {
                $table->string('copy_retrieval_status', 32)->default('n_a')->after('distribution_status');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'wet_signature_verified')) {
                $table->boolean('wet_signature_verified')->default(false)->after('copy_retrieval_status');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'wet_signature_verified_at')) {
                $table->timestamp('wet_signature_verified_at')->nullable()->after('wet_signature_verified');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'wet_signature_verified_by')) {
                $table->unsignedBigInteger('wet_signature_verified_by')->nullable()->after('wet_signature_verified_at');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'client_acknowledged_at')) {
                $table->timestamp('client_acknowledged_at')->nullable()->after('wet_signature_verified_by');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'client_acknowledged_by')) {
                $table->unsignedBigInteger('client_acknowledged_by')->nullable()->after('client_acknowledged_at');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'pending_admin_verification')) {
                $table->boolean('pending_admin_verification')->default(false)->after('client_acknowledged_by');
            }
        });

        // Backfill: previously marked received ⇒ distributed on DnR.
        if (Schema::hasColumn('dcs_distribution_offices', 'office_received_at')
            && Schema::hasColumn('dcs_distribution_offices', 'distribution_status')) {
            DB::table('dcs_distribution_offices')
                ->whereNotNull('office_received_at')
                ->update([
                    'distribution_status' => 'distributed',
                    'wet_signature_verified' => true,
                ]);
        }

        // Backfill copy_retrieval_status from retrieval offices when present.
        if (Schema::hasTable('dcs_retrieval_offices')
            && Schema::hasColumn('dcs_distribution_offices', 'copy_retrieval_status')) {
            $pairs = DB::table('dcs_document_distribution as dist')
                ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
                ->join('dcs_document_retrieval as ret', 'ret.request_id', '=', 'dist.request_id')
                ->join('dcs_retrieval_offices as ro', function ($join) {
                    $join->on('ro.retrieval_id', '=', 'ret.id')
                        ->on('ro.office_id', '=', 'doff.office_id');
                })
                ->get(['doff.id as doff_id', 'ro.retrieval_status']);

            foreach ($pairs as $pair) {
                $status = strtolower(trim((string) ($pair->retrieval_status ?? 'pending'))) === 'retrieved'
                    ? 'retrieved'
                    : 'pending_retrieval';
                DB::table('dcs_distribution_offices')
                    ->where('id', (int) $pair->doff_id)
                    ->update(['copy_retrieval_status' => $status]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return;
        }

        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            foreach ([
                'pending_admin_verification',
                'client_acknowledged_by',
                'client_acknowledged_at',
                'wet_signature_verified_by',
                'wet_signature_verified_at',
                'wet_signature_verified',
                'copy_retrieval_status',
                'distribution_status',
            ] as $col) {
                if (Schema::hasColumn('dcs_distribution_offices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
