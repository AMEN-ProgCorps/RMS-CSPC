<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            if (! Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                $table->unsignedInteger('originator_account_id')->nullable()->after('originator_id');
                $table->foreign('originator_account_id')
                    ->references('id')
                    ->on('account')
                    ->nullOnDelete();
            }
        });

        $this->backfillOriginatorAccounts();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dcs_masterlist_registration')) {
            return;
        }

        Schema::table('dcs_masterlist_registration', function (Blueprint $table) {
            if (Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
                $table->dropForeign(['originator_account_id']);
                $table->dropColumn('originator_account_id');
            }
        });
    }

    private function backfillOriginatorAccounts(): void
    {
        if (! Schema::hasColumn('dcs_masterlist_registration', 'originator_account_id')) {
            return;
        }

        $rows = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereNull('ml.originator_account_id')
            ->whereNotNull('ml.originator_name')
            ->whereRaw("TRIM(ml.originator_name) <> ''")
            ->select([
                'ml.id',
                'ml.originator_name',
                'dr.created_by',
            ])
            ->get();

        foreach ($rows as $row) {
            $accountId = $this->matchAccountIdForOriginatorName((string) $row->originator_name);
            if ($accountId === null && ! empty($row->created_by)) {
                $creatorName = $this->displayNameForAccount((int) $row->created_by);
                if ($this->namesMatch((string) $row->originator_name, $creatorName)) {
                    $accountId = (int) $row->created_by;
                }
            }

            if ($accountId !== null) {
                DB::table('dcs_masterlist_registration')
                    ->where('id', $row->id)
                    ->update(['originator_account_id' => $accountId]);
            }
        }
    }

    private function matchAccountIdForOriginatorName(string $originatorName): ?int
    {
        $accounts = DB::table('account as a')
            ->join('account_details as ad', 'ad.account_id', '=', 'a.id')
            ->select([
                'a.id',
                'ad.first_name',
                'ad.middle_name',
                'ad.last_name',
                'a.username',
            ])
            ->get();

        foreach ($accounts as $account) {
            $display = $this->buildDisplayName(
                (string) ($account->first_name ?? ''),
                (string) ($account->middle_name ?? ''),
                (string) ($account->last_name ?? ''),
                (string) ($account->username ?? '')
            );
            if ($this->namesMatch($originatorName, $display)) {
                return (int) $account->id;
            }
        }

        return null;
    }

    private function displayNameForAccount(int $accountId): string
    {
        $row = DB::table('account as a')
            ->leftJoin('account_details as ad', 'ad.account_id', '=', 'a.id')
            ->where('a.id', $accountId)
            ->select([
                'ad.first_name',
                'ad.middle_name',
                'ad.last_name',
                'a.username',
            ])
            ->first();

        if (! $row) {
            return '';
        }

        return $this->buildDisplayName(
            (string) ($row->first_name ?? ''),
            (string) ($row->middle_name ?? ''),
            (string) ($row->last_name ?? ''),
            (string) ($row->username ?? '')
        );
    }

    private function buildDisplayName(string $first, string $middle, string $last, string $username): string
    {
        $parts = array_filter([trim($first), trim($middle), trim($last)]);

        return $parts !== [] ? implode(' ', $parts) : trim($username);
    }

    private function namesMatch(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));

        return $a !== '' && $b !== '' && $a === $b;
    }
};
