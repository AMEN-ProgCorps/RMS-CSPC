<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;

class DtsExportHelper
{
    /**
     * Map category slug to trans_type used in database
     */
    public static function resolveTransType(string $category): string
    {
        return match ($category) {
            'internal'            => 'internal',
            'external'            => 'external',
            'application_letters',
            'application-letters' => 'others',
            'issuances'           => 'memorandom',
            default               => $category,
        };
    }

    /**
     * Human-readable title for transaction category
     */
    public static function getCategoryTitle(string $category): string
    {
        return match ($category) {
            'internal'            => 'Internal Transactions',
            'external'            => 'External Transactions',
            'application_letters',
            'application-letters' => 'Application Letters',
            'issuances'           => 'Issuances',
            default               => ucfirst(str_replace(['-', '_'], ' ', $category)),
        };
    }

    /**
     * Get list of available columns and their default inclusion state per category
     */
    public static function getAvailableColumns(string $category): array
    {
        $category = str_replace('-', '_', $category);

        return match ($category) {
            'internal' => [
                'item_no'          => ['label' => 'Item No.', 'default' => true],
                'control_number'   => ['label' => 'Control No.', 'default' => true],
                'qr_code'          => ['label' => 'QR Code', 'default' => true],
                'date_created'     => ['label' => 'Date Created', 'default' => true],
                'originated_office'=> ['label' => 'Originating Office', 'default' => true],
                'subject'          => ['label' => 'Subject', 'default' => true],
                'classification'   => ['label' => 'Priority / Classification', 'default' => true],
                'step1_received'   => ['label' => 'Step 1 Received', 'default' => false],
                'step1_released'   => ['label' => 'Step 1 Released', 'default' => false],
                'step2_received'   => ['label' => 'Step 2 Received', 'default' => false],
                'step2_released'   => ['label' => 'Step 2 Released', 'default' => false],
                'current_office'   => ['label' => 'Current Office', 'default' => false],
                'document_name'    => ['label' => 'Document / File Code', 'default' => false],
                'received_by'      => ['label' => 'Latest Received By', 'default' => false],
                'remarks'          => ['label' => 'Latest Remarks', 'default' => false],
                'elapsed_days'     => ['label' => 'Elapsed Days', 'default' => true],
                'status'           => ['label' => 'Status', 'default' => true],
            ],

            'external' => [
                'item_no'          => ['label' => 'Item No.', 'default' => true],
                'control_number'   => ['label' => 'Control No.', 'default' => true],
                'qr_code'          => ['label' => 'QR Code', 'default' => true],
                'date_created'     => ['label' => 'Date Created', 'default' => true],
                'requestor_name'   => ['label' => 'Originator / Requestor', 'default' => true],
                'source_office'    => ['label' => 'Source Agency / Office', 'default' => true],
                'subject'          => ['label' => 'Subject', 'default' => true],
                'classification'   => ['label' => 'Priority / Classification', 'default' => true],
                'requestor_position'=> ['label' => 'Requestor Position', 'default' => false],
                'routing_path_text'=> ['label' => 'Routing Flow (Text)', 'default' => false],
                'current_office'   => ['label' => 'Current Office', 'default' => false],
                'received_by'      => ['label' => 'Latest Received By', 'default' => false],
                'remarks'          => ['label' => 'Latest Remarks', 'default' => false],
                'elapsed_days'     => ['label' => 'Elapsed Days', 'default' => true],
                'status'           => ['label' => 'Status', 'default' => true],
            ],

            'application_letters',
            'application-letters' => [
                'item_no'          => ['label' => 'Item No.', 'default' => true],
                'control_number'   => ['label' => 'Control No.', 'default' => true],
                'qr_code'          => ['label' => 'QR Code', 'default' => true],
                'date_created'     => ['label' => 'Date Created', 'default' => true],
                'requestor_name'   => ['label' => 'Name of Applicant', 'default' => true],
                'position_applied' => ['label' => 'Position Applied', 'default' => true],
                'originated_office'=> ['label' => 'Department / Office', 'default' => true],
                'released_date'    => ['label' => 'Released Date', 'default' => true],
                'released_time'    => ['label' => 'Released Time', 'default' => true],
                'released_by'      => ['label' => 'Released By', 'default' => true],
                'received_date'    => ['label' => 'Received Date', 'default' => true],
                'received_time'    => ['label' => 'Received Time', 'default' => true],
                'received_by'      => ['label' => 'Received By', 'default' => true],
                'subject'          => ['label' => 'Subject', 'default' => false],
                'current_office'   => ['label' => 'Current Office', 'default' => false],
                'remarks'          => ['label' => 'Latest Remarks', 'default' => false],
                'elapsed_days'     => ['label' => 'Elapsed Days', 'default' => true],
                'status'           => ['label' => 'Status', 'default' => true],
            ],

            'issuances' => [
                'item_no'          => ['label' => 'Item No.', 'default' => true],
                'control_number'   => ['label' => 'Control No.', 'default' => true],
                'qr_code'          => ['label' => 'QR Code', 'default' => true],
                'date_created'     => ['label' => 'Date Created', 'default' => true],
                'classification'   => ['label' => 'Type of Issuance', 'default' => true],
                'originated_office'=> ['label' => 'Originator', 'default' => true],
                'subject'          => ['label' => 'Subject', 'default' => true],
                'routing_path_text'=> ['label' => 'Routing Flow (Text)', 'default' => false],
                'current_office'   => ['label' => 'Current Office', 'default' => false],
                'received_by'      => ['label' => 'Latest Received By', 'default' => false],
                'remarks'          => ['label' => 'Latest Remarks', 'default' => false],
                'elapsed_days'     => ['label' => 'Elapsed Days', 'default' => true],
                'status'           => ['label' => 'Status', 'default' => true],
            ],

            default => [
                'item_no'          => ['label' => 'Item No.', 'default' => true],
                'control_number'   => ['label' => 'Control No.', 'default' => true],
                'qr_code'          => ['label' => 'QR Code', 'default' => true],
                'date_created'     => ['label' => 'Date Created', 'default' => true],
                'subject'          => ['label' => 'Subject', 'default' => true],
                'status'           => ['label' => 'Status', 'default' => true],
            ],
        };
    }

    /**
     * Fetch and transform export records based on category and filters / scope
     */
    public static function fetchExportRecords(
        string $category,
        array $filters = [],
        array $selectedIds = [],
        string $scope = 'all'
    ): array {
        $transType = self::resolveTransType($category);
        $user = Auth::user();
        $userOfficeCode = $user?->details?->office?->office_code 
            ?? \App\Services\DocumentStorageService::resolveOfficeCode($user);

        $query = DB::table('dts_transactions as dt')
            ->join('dts_transaction_details as dtd', 'dtd.id', '=', 'dt.transaction_id')
            ->leftJoin('dts_requestor_history as req', 'req.id', '=', 'dtd.requestor_id')
            ->leftJoin('dts_source_office as src', 'src.s_office_code', '=', 'dtd.source_office')
            ->leftJoin('office as originated_office', 'originated_office.office_code', '=', 'dtd.originated_from')
            ->leftJoin('office as current_office', 'current_office.office_code', '=', 'dt.current_office')
            ->leftJoin('dts_transaction_flow as flow', 'flow.flow_code', '=', 'dtd.transaction_flow')
            ->leftJoin('document_data as doc', 'doc.document_path', '=', 'dt.doc_dir')
            ->where('dt.trans_type', $transType);

        // Office permission scope
        $canViewAll = $user?->permissions?->is_sadm || $user?->permissions?->can_dts_view_all_list;
        if (!$canViewAll) {
            $query->where('dtd.originated_from', $userOfficeCode);
        }

        // Scope filter
        if ($scope === 'selected' && !empty($selectedIds)) {
            $query->whereIn('dt.transaction_id', $selectedIds);
        } else {
            // Apply search & dropdown filters
            if (!empty($filters['priority']) && $filters['priority'] !== 'all') {
                $query->where('dtd.classification', $filters['priority']);
            }

            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $query->where('dt.status', $filters['status']);
            }

            if (!empty($filters['search'])) {
                $searchVal = trim($filters['search']);
                $decoded = base64_decode($searchVal, true);
                if ($decoded !== false && preg_match('/^[A-Z0-9-]+$/i', $decoded)) {
                    $searchVal = $decoded;
                }
                $query->where(function ($q) use ($searchVal, $filters) {
                    $q->where('dtd.control_number', 'like', '%' . $searchVal . '%')
                      ->orWhere('dtd.subject', 'like', '%' . $filters['search'] . '%')
                      ->orWhere('req.requestor_name', 'like', '%' . $filters['search'] . '%')
                      ->orWhere('dt.qr_code', 'like', '%' . $searchVal . '%');
                });
            }

            if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
                $from = !empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->startOfDay() : null;
                $to = !empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->endOfDay() : null;

                if ($from && $to && $from->gt($to)) {
                    $temp = $from;
                    $from = $to->copy()->startOfDay();
                    $to = $temp->copy()->endOfDay();
                }

                if ($from && $to) {
                    $query->whereBetween('dtd.date_created', [$from->toDateTimeString(), $to->toDateTimeString()]);
                } elseif ($from) {
                    $query->where('dtd.date_created', '>=', $from->toDateTimeString());
                } elseif ($to) {
                    $query->where('dtd.date_created', '<=', $to->toDateTimeString());
                }
            }
        }

        $sortDirection = (!empty($filters['sort_order']) && in_array(strtolower($filters['sort_order']), ['asc', 'desc'])) 
            ? strtolower($filters['sort_order']) 
            : 'desc';

        $records = $query->select(
            'dt.transaction_id',
            'dt.status',
            'dt.sequence',
            'dt.qr_code',
            'dt.current_office',
            'dtd.control_number',
            'req.requestor_name',
            'req.requestor_position',
            'dtd.subject',
            'dtd.classification',
            'dtd.action_needed',
            'dtd.date_created',
            'src.s_office_name',
            'src.s_office_code',
            'dtd.source_office',
            'dtd.originated_from',
            DB::raw("COALESCE(NULLIF(flow.flow_name, ''), flow.referenced_flow) as doc_type_name"),
            'originated_office.office_name as originated_office_name',
            'current_office.office_name as current_office_name',
            'doc.document_name'
        )
        ->orderBy('dtd.date_created', $sortDirection)
        ->get();

        $rows = [];
        $itemNumber = 1;

        foreach ($records as $t) {
            $logs = DB::table('sub_document_tracking_system_logs as log')
                ->leftJoin('account_details as ad', 'ad.account_id', '=', 'log.performed_by')
                ->leftJoin('office as o', 'o.office_code', '=', 'log.office_code')
                ->where('log.transaction_id', $t->transaction_id)
                ->orderBy('log.id', 'asc')
                ->select('log.*', 'ad.first_name', 'ad.last_name', 'o.office_name')
                ->get();

            $firstLog = $logs->first();
            $secondLog = $logs->count() > 1 ? $logs->get(1) : null;
            $latestLog = $logs->last();

            $remarks = $latestLog && !empty($latestLog->notes) ? $latestLog->notes : '-';
            $receivedBy = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';

            // Step 1 / Step 2
            $step1Received = $firstLog && $firstLog->date_in ? Carbon::parse($firstLog->date_in)->format('Y-m-d H:i') : ($t->date_created ? Carbon::parse($t->date_created)->format('Y-m-d H:i') : '-');
            $step1Released = $firstLog && $firstLog->date_out ? Carbon::parse($firstLog->date_out)->format('Y-m-d H:i') : ($logs->count() > 1 ? Carbon::parse($firstLog?->date_in ?? $t->date_created)->format('Y-m-d H:i') : '-');
            $step2Received = $secondLog && $secondLog->date_in ? Carbon::parse($secondLog->date_in)->format('Y-m-d H:i') : '-';
            $step2Released = $secondLog && $secondLog->date_out ? Carbon::parse($secondLog->date_out)->format('Y-m-d H:i') : '-';

            // Released / Received date/time/by for Application Letters
            $relLog = $logs->first(fn($l) => !is_null($l->date_out));
            $recLog = $logs->first(fn($l) => !is_null($l->date_in));

            $releasedDate = $relLog && $relLog->date_out ? Carbon::parse($relLog->date_out)->format('Y-m-d') : ($firstLog && $firstLog->date_out ? Carbon::parse($firstLog->date_out)->format('Y-m-d') : '-');
            $releasedTime = $relLog && $relLog->date_out ? Carbon::parse($relLog->date_out)->format('H:i') : ($firstLog && $firstLog->date_out ? Carbon::parse($firstLog->date_out)->format('H:i') : '-');
            $releasedBy = $relLog && $relLog->first_name ? ($relLog->first_name . ' ' . $relLog->last_name) : ($firstLog && $firstLog->first_name ? ($firstLog->first_name . ' ' . $firstLog->last_name) : '-');

            $receivedDate = $recLog && $recLog->date_in ? Carbon::parse($recLog->date_in)->format('Y-m-d') : '-';
            $receivedTime = $recLog && $recLog->date_in ? Carbon::parse($recLog->date_in)->format('H:i') : '-';
            $recBy = $recLog && $recLog->first_name ? ($recLog->first_name . ' ' . $recLog->last_name) : '-';

            // Position applied for Application letters
            $pos = $t->requestor_position;
            if (empty($pos) && !empty($t->subject) && str_starts_with($t->subject, 'Application for ')) {
                $pos = substr($t->subject, 16);
            }
            $positionApplied = $pos ?: ($t->classification ?: 'N/A');

            // Elapsed Days
            $createdDateRaw = $firstLog?->date_in ?? $t->date_created;
            $elapsedDays = $createdDateRaw ? (int) abs(now()->diffInDays(Carbon::parse($createdDateRaw))) : 0;

            // Clean text representation of routing flow
            $flowSteps = [];
            foreach ($logs as $l) {
                if (!empty($l->office_code)) {
                    $flowSteps[] = $l->office_code;
                }
            }
            $routingPathText = !empty($flowSteps) ? implode(' -> ', array_unique($flowSteps)) : '-';

            $rows[] = [
                'item_no'           => $itemNumber++,
                'transaction_id'    => $t->transaction_id,
                'control_number'    => $t->control_number,
                'qr_code'           => $t->qr_code,
                'date_created'      => $t->date_created ? Carbon::parse($t->date_created)->format('Y-m-d H:i') : '-',
                'originated_office' => $t->originated_office_name ?: $t->originated_from,
                'current_office'    => $t->current_office_name ?: ($t->current_office ?: '-'),
                'subject'           => $t->subject ?: '-',
                'classification'    => ucfirst(str_replace('_', ' ', $t->classification ?: 'Simple')),
                'requestor_name'    => $t->requestor_name ?: ($t->originated_office_name ?: $t->originated_from),
                'requestor_position'=> $t->requestor_position ?: '-',
                'position_applied'  => $positionApplied,
                'source_office'     => $t->s_office_name ?: ($t->source_office ?: ($t->originated_office_name ?: '-')),
                'document_name'     => $t->document_name ?: '-',
                'step1_received'    => $step1Received,
                'step1_released'    => $step1Released,
                'step2_received'    => $step2Received,
                'step2_released'    => $step2Released,
                'released_date'     => $releasedDate,
                'released_time'     => $releasedTime,
                'released_by'       => $releasedBy,
                'received_date'     => $receivedDate,
                'received_time'     => $receivedTime,
                'received_by'       => $recBy !== '-' ? $recBy : $receivedBy,
                'remarks'           => $remarks,
                'routing_path_text' => $routingPathText,
                'elapsed_days'      => $elapsedDays . ' day(s)',
                'status'            => ucfirst($t->status ?: 'Ongoing'),
            ];
        }

        return $rows;
    }

    /**
     * Stream Excel-compatible CSV download with UTF-8 BOM
     */
    public static function exportCsv(
        string $filename,
        array $selectedColumns,
        array $rows,
        array $availableColumns
    ): StreamedResponse {
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($selectedColumns, $rows, $availableColumns) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel properly opens with formatting & accents
            fwrite($handle, "\xEF\xBB\xBF");

            // Output Header Row
            $headerLabels = [];
            foreach ($selectedColumns as $colKey) {
                $headerLabels[] = $availableColumns[$colKey]['label'] ?? ucfirst(str_replace('_', ' ', $colKey));
            }
            fputcsv($handle, $headerLabels);

            // Output Data Rows
            foreach ($rows as $row) {
                $line = [];
                foreach ($selectedColumns as $colKey) {
                    $line[] = $row[$colKey] ?? '';
                }
                fputcsv($handle, $line);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
