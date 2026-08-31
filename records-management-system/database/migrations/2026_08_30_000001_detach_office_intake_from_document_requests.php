<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Office intake DRF/DCN are pre-registration forms only.
 * Detach them from dcs_document_requests so they never appear in RFIO inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_document_requests')) {
            return;
        }

        $intakeRequestIds = collect();

        if (Schema::hasTable('dcs_document_request_form') && Schema::hasColumn('dcs_document_request_form', 'is_office_intake')) {
            $intakeRequestIds = $intakeRequestIds->merge(
                DB::table('dcs_document_request_form')
                    ->where('is_office_intake', true)
                    ->whereNotNull('request_id')
                    ->pluck('request_id')
            );

            DB::table('dcs_document_request_form')
                ->where('is_office_intake', true)
                ->update(['request_id' => null]);
        }

        if (Schema::hasTable('dcs_document_change_notice') && Schema::hasColumn('dcs_document_change_notice', 'is_office_intake')) {
            $intakeRequestIds = $intakeRequestIds->merge(
                DB::table('dcs_document_change_notice')
                    ->where('is_office_intake', true)
                    ->whereNotNull('request_id')
                    ->pluck('request_id')
            );

            DB::table('dcs_document_change_notice')
                ->where('is_office_intake', true)
                ->update(['request_id' => null]);
        }

        foreach ($intakeRequestIds->unique()->filter() as $requestId) {
            $requestId = (int) $requestId;
            if ($requestId < 1) {
                continue;
            }

            $hasMasterlist = Schema::hasTable('dcs_masterlist_registration')
                && DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->exists();

            if ($hasMasterlist) {
                continue;
            }

            $hasRegisteredDrf = Schema::hasTable('dcs_document_request_form')
                && DB::table('dcs_document_request_form')
                    ->where('request_id', $requestId)
                    ->where(function ($q) {
                        $q->whereNull('is_office_intake')->orWhere('is_office_intake', false);
                    })
                    ->exists();

            if ($hasRegisteredDrf) {
                continue;
            }

            $hasRegisteredDcn = Schema::hasTable('dcs_document_change_notice')
                && DB::table('dcs_document_change_notice')
                    ->where('request_id', $requestId)
                    ->where(function ($q) {
                        $q->whereNull('is_office_intake')->orWhere('is_office_intake', false);
                    })
                    ->exists();

            if ($hasRegisteredDcn) {
                continue;
            }

            DB::table('dcs_document_requests')->where('id', $requestId)->delete();
        }
    }

    public function down(): void
    {
        // Non-reversible: office intake rows are intentionally detached from inventory.
    }
};
