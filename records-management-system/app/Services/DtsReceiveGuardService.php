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
     * Resolve the active DTS transaction logs table name across legacy and modern schema revisions.
     *
     * @return string Active table name ('dts_transaction_logs' or 'sub_document_tracking_system_logs').
     */
    public static function logsTable(): string
    {
        return Schema::hasTable('dts_transaction_logs') ? 'dts_transaction_logs' : 'sub_document_tracking_system_logs';
    }

    /**
     * Retrieve the most recent log record for a given transaction at a specific office.
     *
     * @param string|int|null $transactionId The unique transaction identifier.
     * @param string|null $officeCode The office code to query logs for.
     * @return object|null The most recent log row object, or null if none found.
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
     * Determine if a transaction log record signifies that the office has received custody of the document.
     *
     * Returns true if log type is 'received' or has non-empty date_in (and not yet forwarded).
     * Mirrors the shared predicate used across scanners, widgets, and tables.
     *
     * @param object|null $log Log row object to evaluate.
     * @return bool True if custody was received; false otherwise.
     */
    public static function isReceived($log): bool
    {
        return (bool) ($log
            && ($log->type === 'received'
                || (!empty($log->date_in) && $log->type !== 'forwarded')));
    }

    /**
     * Fast optimistic pre-check (lockless) to test if an office has already received a transaction.
     *
     * Used before initiating workflows or displaying UI action buttons.
     *
     * @param string|int|null $transactionId The transaction ID.
     * @param string|null $officeCode The office code.
     * @return bool True if already received at the given office; false otherwise.
     */
    public static function alreadyReceived($transactionId, ?string $officeCode): bool
    {
        return self::isReceived(self::lastLogForOffice($transactionId, $officeCode));
    }

    /**
     * Acquire a pessimistic row-level lock on the transaction record to serialize concurrent operations.
     *
     * Must be called as the FIRST statement inside a DB::transaction closure so the row lock
     * is held until commit, preventing double-receive race conditions between cameras and guns.
     *
     * @param string|int|null $transactionId The transaction ID to lock.
     * @return void
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
