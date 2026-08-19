<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $table->string('revision_status', 20)->default('latest')->after('revise_no');
            $table->index(['doc_no', 'revision_status'], 'dcs_ml_doc_no_revision_status_idx');
        });

        $families = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereNotNull('ml.doc_no')
            ->where('ml.doc_no', '!=', '')
            ->select([
                'ml.id',
                'ml.doc_no',
                'ml.revise_no',
                'dr.doc_type_id',
                'dr.sub_type_id',
            ])
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->get()
            ->groupBy(function ($row) {
                return $row->doc_no.'||'.$row->doc_type_id.'||'.(int) ($row->sub_type_id ?? 0);
            });

        foreach ($families as $rows) {
            $latestId = $rows->first()->id;
            $obsoleteIds = $rows->pluck('id')->reject(fn ($id) => (int) $id === (int) $latestId)->values()->all();

            DB::table('dcs_masterlist_registration')
                ->where('id', $latestId)
                ->update(['revision_status' => 'latest']);

            if ($obsoleteIds !== []) {
                DB::table('dcs_masterlist_registration')
                    ->whereIn('id', $obsoleteIds)
                    ->update(['revision_status' => 'obsolete']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            $table->dropIndex('dcs_ml_doc_no_revision_status_idx');
            $table->dropColumn('revision_status');
        });
    }
};
