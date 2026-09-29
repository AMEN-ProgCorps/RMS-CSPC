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
            if (! Schema::hasColumn('dcs_distribution_offices', 'copy_no')) {
                $table->unsignedInteger('copy_no')->nullable()->after('copies');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'distribution_status')) {
                $table->string('distribution_status', 32)->default('pending_pickup')->after('copy_no');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'copy_retrieval_status')) {
                $table->string('copy_retrieval_status', 32)->default('na')->after('distribution_status');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'old_version_label')) {
                $table->string('old_version_label', 80)->nullable()->after('copy_retrieval_status');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'physical_signature_verified')) {
                $table->boolean('physical_signature_verified')->default(false)->after('old_version_label');
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'physical_signature_verified_at')) {
                $table->timestamp('physical_signature_verified_at')->nullable();
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'physical_signature_verified_by')) {
                $table->unsignedBigInteger('physical_signature_verified_by')->nullable();
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'client_acknowledged_at')) {
                $table->timestamp('client_acknowledged_at')->nullable();
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'client_acknowledged_by')) {
                $table->unsignedBigInteger('client_acknowledged_by')->nullable();
            }
            if (! Schema::hasColumn('dcs_distribution_offices', 'verification_required')) {
                $table->boolean('verification_required')->default(false);
            }
        });

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_distribution_offices')) {
            return;
        }

        Schema::table('dcs_distribution_offices', function (Blueprint $table) {
            foreach ([
                'verification_required',
                'client_acknowledged_by',
                'client_acknowledged_at',
                'physical_signature_verified_by',
                'physical_signature_verified_at',
                'physical_signature_verified',
                'old_version_label',
                'copy_retrieval_status',
                'distribution_status',
                'copy_no',
            ] as $col) {
                if (Schema::hasColumn('dcs_distribution_offices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function backfill(): void
    {
        if (! Schema::hasColumn('dcs_distribution_offices', 'distribution_status')) {
            return;
        }

        $hasReceived = Schema::hasColumn('dcs_distribution_offices', 'office_received_at');

        $rows = DB::table('dcs_distribution_offices')
            ->orderBy('distribution_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'id',
                'distribution_id',
                'copies',
                'copy_no',
                'distribution_status',
                $hasReceived ? 'office_received_at' : DB::raw('null as office_received_at'),
            ]);

        $nextCopy = [];
        foreach ($rows as $row) {
            $distId = (int) $row->distribution_id;
            $copies = max(1, (int) ($row->copies ?? 1));
            $copyNo = (int) ($row->copy_no ?? 0);
            if ($copyNo < 1) {
                $copyNo = $nextCopy[$distId] ?? 1;
            }
            $nextCopy[$distId] = $copyNo + $copies;

            $distributed = $hasReceived && ! empty($row->office_received_at);
            $update = ['copy_no' => $copyNo];
            if (($row->distribution_status ?? '') === '' || $row->distribution_status === 'pending_pickup') {
                $update['distribution_status'] = $distributed ? 'distributed' : 'pending_pickup';
            }
            if ($distributed) {
                $update['physical_signature_verified'] = true;
            }

            DB::table('dcs_distribution_offices')->where('id', $row->id)->update($update);
        }
    }
};
