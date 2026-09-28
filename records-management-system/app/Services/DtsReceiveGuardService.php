<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time RECEIVE guard for DTS transactions.
 *
 * A document must only ever be received ONCE per office visit. Every receive
 * entry point (advanced scanner, receive console, transactions table, incoming
 * list) shares the same predicate so the UI flag and the server-side guard can
 * never disagree.
 */
class DtsReceiveGuardService
{
    /**
     * Transaction log table (renamed across versions).
     */
    public static function logsTable(): string
    {
        return Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
    }

    /**
     * Latest log row this office has for the transaction.
     */
    public static function lastLogForOffice($transactionId, ?string $officeCode)
    {
        if ($transactionId === null || $transactionId === '' || $officeCode === null || $officeCode === '') {
            return null;
        }

        return DB::table(self::logsTable())
            ->where('transaction_id', $transactionId)
            ->where('office_code', $officeCode)
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Does a log row mean "this office already has the document in custody"?
     * Mirrors the flag used by the scanner / receive UI.
     */
    public static function isReceived($log): bool
    {
        return (bool) ($log
            && ($log->type === 'received'
                || (!empty($log->date_in) && $log->type !== 'forwarded')));
    }

    /**
     * Fast pre-check (no locks) used before starting an action.
     */
    public static function alreadyReceived($transactionId, ?string $officeCode): bool
    {
        return self::isReceived(self::lastLogForOffice($transactionId, $officeCode));
    }

    /**
     * Serialize concurrent receive attempts for the same transaction.
     *
     * Must be called as the FIRST statement inside the action's DB::transaction
     * so the row lock (held until that transaction ends) makes the following
     * alreadyReceived() re-check authoritative.
     */
    public static function lockTransaction($transactionId): void
    {
        if ($transactionId === null || $transactionId === '') {
            return;
        }

        DB::table('dts_transactions')
            ->where('transaction_id', $transactionId)
            ->lockForUpdate()
            ->first();
    }
}
