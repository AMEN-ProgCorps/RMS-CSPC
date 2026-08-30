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
     * Define the 5 standard routing columns for any dynamic Flow step $i
     */
    public static function getFlowColumnDefinitions(int $i): array
    {
        $flowTitle = ($i === 1) ? 'Flow 1 (not the origin)' : "Flow {$i}";
        return [
            "flow{$i}_office"       => ['label' => "Flow {$i}: Office Name", 'sublabel' => 'Office Name', 'default' => false, 'group' => "flow{$i}", 'flow_index' => $i, 'flow_title' => $flowTitle],
            "flow{$i}_received"     => ['label' => "Flow {$i}: Received", 'sublabel' => 'Received', 'default' => false, 'group' => "flow{$i}", 'flow_index' => $i, 'flow_title' => $flowTitle],
            "flow{$i}_released"     => ['label' => "Flow {$i}: Released", 'sublabel' => 'Released', 'default' => false, 'group' => "flow{$i}", 'flow_index' => $i, 'flow_title' => $flowTitle],
            "flow{$i}_notes"        => ['label' => "Flow {$i}: Notes", 'sublabel' => 'Notes', 'default' => false, 'group' => "flow{$i}", 'flow_index' => $i, 'flow_title' => $flowTitle],
            "flow{$i}_elapsed_days" => ['label' => "Flow {$i}: Elapsed Day", 'sublabel' => 'Elapsed Day', 'default' => false, 'group' => "flow{$i}", 'flow_index' => $i, 'flow_title' => $flowTitle],
        ];
    }

    /**
     * Get list of available columns and their default inclusion state per category
     */
    public static function getAvailableColumns(string $category, int $flowCount = 0): array
    {
        $category = str_replace('-', '_', $category);

        $base = match ($category) {
            'internal' => [
                // General Transaction Info (Default: Included)
                'item_no'           => ['label' => 'Item No.', 'sublabel' => 'No.', 'default' => true, 'group' => 'general'],
                'control_number'    => ['label' => 'Control No.', 'sublabel' => 'Control No.', 'default' => true, 'group' => 'general'],
                'qr_code'           => ['label' => 'QR Code', 'sublabel' => 'QR Code', 'default' => true, 'group' => 'general'],
                'date_created'      => ['label' => 'Date Created', 'sublabel' => 'Date Created', 'default' => true, 'group' => 'general'],
                'originated_office' => ['label' => 'Originating Office', 'sublabel' => 'Originating Office', 'default' => true, 'group' => 'general'],
                'subject'           => ['label' => 'Subject', 'sublabel' => 'Subject', 'default' => true, 'group' => 'general'],
                'classification'    => ['label' => 'Priority / Classification', 'sublabel' => 'Classification', 'default' => true, 'group' => 'general'],
                'elapsed_days'      => ['label' => 'Total Elapsed Days', 'sublabel' => 'Elapsed Days', 'default' => true, 'group' => 'general'],
                'status'            => ['label' => 'Status', 'sublabel' => 'Status', 'default' => true, 'group' => 'general'],

                // Additional Metadata (Default: NOT Included)
                'current_office'    => ['label' => 'Current Office', 'sublabel' => 'Current Office', 'default' => false, 'group' => 'additional'],
                'document_name'     => ['label' => 'Document / File Code', 'sublabel' => 'File Code', 'default' => false, 'group' => 'additional'],
                'received_by'       => ['label' => 'Latest Received By', 'sublabel' => 'Received By', 'default' => false, 'group' => 'additional'],
                'remarks'           => ['label' => 'Latest Remarks', 'sublabel' => 'Remarks', 'default' => false, 'group' => 'additional'],
            ],

            'external' => [
                'item_no'           => ['label' => 'Item No.', 'sublabel' => 'No.', 'default' => true, 'group' => 'general'],
                'control_number'    => ['label' => 'Control No.', 'sublabel' => 'Control No.', 'default' => true, 'group' => 'general'],
                'qr_code'           => ['label' => 'QR Code', 'sublabel' => 'QR Code', 'default' => true, 'group' => 'general'],
                'date_created'      => ['label' => 'Date Created', 'sublabel' => 'Date Created', 'default' => true, 'group' => 'general'],
                'requestor_name'    => ['label' => 'Originator / Requestor', 'sublabel' => 'Requestor', 'default' => true, 'group' => 'general'],
                'source_office'     => ['label' => 'Source Agency / Office', 'sublabel' => 'Source Office', 'default' => true, 'group' => 'general'],
                'subject'           => ['label' => 'Subject', 'sublabel' => 'Subject', 'default' => true, 'group' => 'general'],
                'classification'    => ['label' => 'Priority / Classification', 'sublabel' => 'Classification', 'default' => true, 'group' => 'general'],
                'elapsed_days'      => ['label' => 'Total Elapsed Days', 'sublabel' => 'Elapsed Days', 'default' => true, 'group' => 'general'],
                'status'            => ['label' => 'Status', 'sublabel' => 'Status', 'default' => true, 'group' => 'general'],

                // Additional Metadata
                'requestor_position'=> ['label' => 'Requestor Position', 'sublabel' => 'Position', 'default' => false, 'group' => 'additional'],
                'routing_path_text' => ['label' => 'Routing Flow (Text)', 'sublabel' => 'Routing Flow', 'default' => false, 'group' => 'additional'],
                'current_office'    => ['label' => 'Current Office', 'sublabel' => 'Current Office', 'default' => false, 'group' => 'additional'],
                'received_by'       => ['label' => 'Latest Received By', 'sublabel' => 'Received By', 'default' => false, 'group' => 'additional'],
                'remarks'           => ['label' => 'Latest Remarks', 'sublabel' => 'Remarks', 'default' => false, 'group' => 'additional'],
            ],

            'application_letters',
            'application-letters' => [
                'item_no'           => ['label' => 'Item No.', 'sublabel' => 'No.', 'default' => true, 'group' => 'general'],
                'control_number'    => ['label' => 'Control No.', 'sublabel' => 'Control No.', 'default' => true, 'group' => 'general'],
                'qr_code'           => ['label' => 'QR Code', 'sublabel' => 'QR Code', 'default' => true, 'group' => 'general'],
                'date_created'      => ['label' => 'Date Created', 'sublabel' => 'Date Created', 'default' => true, 'group' => 'general'],
                'requestor_name'    => ['label' => 'Name of Applicant', 'sublabel' => 'Applicant', 'default' => true, 'group' => 'general'],
                'position_applied'  => ['label' => 'Position Applied', 'sublabel' => 'Position', 'default' => true, 'group' => 'general'],
                'originated_office' => ['label' => 'Department / Office', 'sublabel' => 'Department', 'default' => true, 'group' => 'general'],
                'released_date'     => ['label' => 'Released Date', 'sublabel' => 'Rel Date', 'default' => true, 'group' => 'general'],
                'released_time'     => ['label' => 'Released Time', 'sublabel' => 'Rel Time', 'default' => true, 'group' => 'general'],
                'released_by'       => ['label' => 'Released By', 'sublabel' => 'Rel By', 'default' => true, 'group' => 'general'],
                'received_date'     => ['label' => 'Received Date', 'sublabel' => 'Rec Date', 'default' => true, 'group' => 'general'],
                'received_time'     => ['label' => 'Received Time', 'sublabel' => 'Rec Time', 'default' => true, 'group' => 'general'],
                'received_by'       => ['label' => 'Received By', 'sublabel' => 'Rec By', 'default' => true, 'group' => 'general'],
                'elapsed_days'      => ['label' => 'Total Elapsed Days', 'sublabel' => 'Elapsed Days', 'default' => true, 'group' => 'general'],
                'status'            => ['label' => 'Status', 'sublabel' => 'Status', 'default' => true, 'group' => 'general'],

                // Additional Metadata
                'subject'           => ['label' => 'Subject', 'sublabel' => 'Subject', 'default' => false, 'group' => 'additional'],
                'current_office'    => ['label' => 'Current Office', 'sublabel' => 'Current Office', 'default' => false, 'group' => 'additional'],
                'remarks'           => ['label' => 'Latest Remarks', 'sublabel' => 'Remarks', 'default' => false, 'group' => 'additional'],
            ],

            'issuances' => [
                'item_no'           => ['label' => 'Item No.', 'sublabel' => 'No.', 'default' => true, 'group' => 'general'],
                'control_number'    => ['label' => 'Control No.', 'sublabel' => 'Control No.', 'default' => true, 'group' => 'general'],
                'qr_code'           => ['label' => 'QR Code', 'sublabel' => 'QR Code', 'default' => true, 'group' => 'general'],
                'date_created'      => ['label' => 'Date Created', 'sublabel' => 'Date Created', 'default' => true, 'group' => 'general'],
                'classification'    => ['label' => 'Type of Issuance', 'sublabel' => 'Type', 'default' => true, 'group' => 'general'],
                'originated_office' => ['label' => 'Originator', 'sublabel' => 'Originator', 'default' => true, 'group' => 'general'],
                'subject'           => ['label' => 'Subject', 'sublabel' => 'Subject', 'default' => true, 'group' => 'general'],
                'elapsed_days'      => ['label' => 'Total Elapsed Days', 'sublabel' => 'Elapsed Days', 'default' => true, 'group' => 'general'],
                'status'            => ['label' => 'Status', 'sublabel' => 'Status', 'default' => true, 'group' => 'general'],

                // Additional Metadata
                'routing_path_text' => ['label' => 'Routing Flow (Text)', 'sublabel' => 'Routing Flow', 'default' => false, 'group' => 'additional'],
                'current_office'    => ['label' => 'Current Office', 'sublabel' => 'Current Office', 'default' => false, 'group' => 'additional'],
                'received_by'       => ['label' => 'Latest Received By', 'sublabel' => 'Received By', 'default' => false, 'group' => 'additional'],
                'remarks'           => ['label' => 'Latest Remarks', 'sublabel' => 'Remarks', 'default' => false, 'group' => 'additional'],
            ],

            default => [
                'item_no'           => ['label' => 'Item No.', 'sublabel' => 'No.', 'default' => true, 'group' => 'general'],
                'control_number'    => ['label' => 'Control No.', 'sublabel' => 'Control No.', 'default' => true, 'group' => 'general'],
                'qr_code'           => ['label' => 'QR Code', 'sublabel' => 'QR Code', 'default' => true, 'group' => 'general'],
                'date_created'      => ['label' => 'Date Created', 'sublabel' => 'Date Created', 'default' => true, 'group' => 'general'],
                'subject'           => ['label' => 'Subject', 'sublabel' => 'Subject', 'default' => true, 'group' => 'general'],
                'status'            => ['label' => 'Status', 'sublabel' => 'Status', 'default' => true, 'group' => 'general'],
            ],
        };

        $result = [];
        // Add General Columns
        foreach ($base as $k => $def) {
            if (($def['group'] ?? '') === 'general') {
                $result[$k] = $def;
            }
        }

        // Add Flow Columns up to $flowCount
        for ($i = 1; $i <= $flowCount; $i++) {
            $result = array_merge($result, self::getFlowColumnDefinitions($i));
        }

        // Add Additional Columns
        foreach ($base as $k => $def) {
            if (($def['group'] ?? '') === 'additional') {
                $result[$k] = $def;
            }
        }

        return $result;
    }

    /**
     * Resolve all columns including any dynamically referenced flow steps
     */
    public static function resolveAllColumns(string $category, array $selectedCols = []): array
    {
        $maxFlow = 0;
        foreach ($selectedCols as $col) {
            if (preg_match('/^flow(\d+)_/', $col, $m)) {
                $maxFlow = max($maxFlow, (int)$m[1]);
            }
        }
        return self::getAvailableColumns($category, $maxFlow);
    }

    /**
     * Fetch and transform export records based on category and selected transaction IDs
     */
    public static function fetchExportRecords(
        string $category,
        array $filters = [],
        array $selectedIds = []
    ): array {
        if (empty($selectedIds)) {
            return [];
        }

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
            ->where('dt.trans_type', $transType)
            ->whereIn('dt.transaction_id', $selectedIds);

        // Office permission scope
        $canViewAll = $user?->permissions?->is_sadm || $user?->permissions?->can_dts_view_all_list;
        if (!$canViewAll) {
            $query->where('dtd.originated_from', $userOfficeCode);
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
            $latestLog = $logs->last();

            $remarks = $latestLog && !empty($latestLog->notes) ? $latestLog->notes : '-';
            $receivedBy = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';

            // Filter non-origin destination logs for flow steps
            $originOfficeCode = $t->originated_from ?: ($firstLog?->office_code ?? '');
            $nonOriginLogs = $logs->filter(function ($l) use ($originOfficeCode) {
                return !empty($l->office_code) && $l->office_code !== $originOfficeCode;
            })->values();

            $relLog = $logs->first(fn($l) => !is_null($l->date_out));
            $recLog = $logs->first(fn($l) => !is_null($l->date_in));

            $releasedDate = $relLog && $relLog->date_out ? Carbon::parse($relLog->date_out)->format('Y-m-d') : ($firstLog && $firstLog->date_out ? Carbon::parse($firstLog->date_out)->format('Y-m-d') : '-');
            $releasedTime = $relLog && $relLog->date_out ? Carbon::parse($relLog->date_out)->format('h:i A') : ($firstLog && $firstLog->date_out ? Carbon::parse($firstLog->date_out)->format('h:i A') : '-');
            $releasedBy = $relLog && $relLog->first_name ? ($relLog->first_name . ' ' . $relLog->last_name) : ($firstLog && $firstLog->first_name ? ($firstLog->first_name . ' ' . $firstLog->last_name) : '-');

            $receivedDate = $recLog && $recLog->date_in ? Carbon::parse($recLog->date_in)->format('Y-m-d') : '-';
            $receivedTime = $recLog && $recLog->date_in ? Carbon::parse($recLog->date_in)->format('h:i A') : '-';
            $recBy = $recLog && $recLog->first_name ? ($recLog->first_name . ' ' . $recLog->last_name) : '-';

            // Position applied for Application letters
            $pos = $t->requestor_position;
            if (empty($pos) && !empty($t->subject) && str_starts_with($t->subject, 'Application for ')) {
                $pos = substr($t->subject, 16);
            }
            $positionApplied = $pos ?: ($t->classification ?: 'N/A');

            // Total Elapsed Days for the entire transaction lifecycle
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

            $rowMap = [
                'item_no'            => $itemNumber++,
                'transaction_id'     => $t->transaction_id,
                'control_number'     => $t->control_number,
                'qr_code'            => $t->qr_code,
                'date_created'       => $t->date_created ? Carbon::parse($t->date_created)->format('Y-m-d h:i A') : '-',
                'originated_office'  => $t->originated_office_name ?: $t->originated_from,
                'current_office'     => $t->current_office_name ?: ($t->current_office ?: '-'),
                'subject'            => $t->subject ?: '-',
                'classification'     => ucfirst(str_replace('_', ' ', $t->classification ?: 'Simple')),
                'requestor_name'     => $t->requestor_name ?: ($t->originated_office_name ?: $t->originated_from),
                'requestor_position' => $t->requestor_position ?: '-',
                'position_applied'   => $positionApplied,
                'source_office'      => $t->s_office_name ?: ($t->source_office ?: ($t->originated_office_name ?: '-')),
                'document_name'      => $t->document_name ?: '-',
                'released_date'      => $releasedDate,
                'released_time'      => $releasedTime,
                'released_by'        => $releasedBy,
                'received_date'      => $receivedDate,
                'received_time'      => $receivedTime,
                'received_by'        => $recBy !== '-' ? $recBy : $receivedBy,
                'remarks'            => $remarks,
                'routing_path_text'  => $routingPathText,
                'elapsed_days'       => $elapsedDays . ' day' . ($elapsedDays === 1 ? '' : 's'),
                'status'             => ucfirst($t->status ?: 'Ongoing'),
            ];

            // Dynamically populate flow steps 1 to 10
            for ($i = 1; $i <= 10; $i++) {
                $fLog = $nonOriginLogs->get($i - 1);
                $fOffice = $fLog ? ($fLog->office_name ?: $fLog->office_code) : '-';
                $fOfficeCode = $fLog?->office_code ?: '';

                $fRecDate = '-';
                $fRecBy = '-';
                if ($fLog && $fLog->date_in) {
                    $fRecDate = Carbon::parse($fLog->date_in)->format('Y-m-d h:i A');
                    $fRecBy = ($fLog->first_name || $fLog->last_name)
                        ? trim($fLog->first_name . ' ' . $fLog->last_name)
                        : 'Received';
                } elseif ($fLog) {
                    $fRecDate = 'Pending Receive';
                }

                $fRelDate = '-';
                $fRelBy = '-';
                if ($fLog && $fLog->date_out) {
                    $fRelDate = Carbon::parse($fLog->date_out)->format('Y-m-d h:i A');
                    $fRelBy = ($fLog->first_name || $fLog->last_name)
                        ? trim($fLog->first_name . ' ' . $fLog->last_name)
                        : 'Released';
                } elseif ($fLog && $fLog->date_in) {
                    $fRelDate = 'In Progress';
                }

                $fElapsedDays = '-';
                if ($fLog && $fLog->date_in) {
                    $start = Carbon::parse($fLog->date_in);
                    $end = $fLog->date_out ? Carbon::parse($fLog->date_out) : now();
                    $diffDays = (int) abs($start->diffInDays($end));
                    $fElapsedDays = $diffDays . ' day' . ($diffDays === 1 ? '' : 's');
                }

                $fRecText = ($fRecDate !== '-' && $fRecDate !== 'Pending Receive')
                    ? ($fRecDate . ($fRecBy !== '-' ? ' (' . $fRecBy . ')' : ''))
                    : $fRecDate;

                $fRelText = ($fRelDate !== '-' && $fRelDate !== 'In Progress')
                    ? ($fRelDate . ($fRelBy !== '-' ? ' (' . $fRelBy . ')' : ''))
                    : $fRelDate;

                $fNotes = ($fLog && !empty($fLog->notes)) ? $fLog->notes : '-';

                $rowMap["flow{$i}_office"]        = $fOffice;
                $rowMap["flow{$i}_office_code"]   = $fOfficeCode;
                $rowMap["flow{$i}_received"]      = $fRecText;
                $rowMap["flow{$i}_received_date"] = $fRecDate;
                $rowMap["flow{$i}_received_by"]   = $fRecBy;
                $rowMap["flow{$i}_released"]      = $fRelText;
                $rowMap["flow{$i}_released_date"] = $fRelDate;
                $rowMap["flow{$i}_released_by"]   = $fRelBy;
                $rowMap["flow{$i}_notes"]         = $fNotes;
                $rowMap["flow{$i}_elapsed_days"]  = $fElapsedDays;
            }

            $rows[] = $rowMap;
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
