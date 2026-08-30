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

                // Flow 1 Group (Not the Origin) - Default: NOT Included (opt-in)
                'flow1_office'       => ['label' => 'Flow 1: Office Name', 'sublabel' => 'Office Name', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_received'     => ['label' => 'Flow 1: Received', 'sublabel' => 'Received', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_released'     => ['label' => 'Flow 1: Released', 'sublabel' => 'Released', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_elapsed_days' => ['label' => 'Flow 1: Elapsed Day', 'sublabel' => 'Elapsed Day', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],

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

                // Flow 1 Group (Not the Origin) - Default: NOT Included (opt-in)
                'flow1_office'       => ['label' => 'Flow 1: Office Name', 'sublabel' => 'Office Name', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_received'     => ['label' => 'Flow 1: Received', 'sublabel' => 'Received', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_released'     => ['label' => 'Flow 1: Released', 'sublabel' => 'Released', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_elapsed_days' => ['label' => 'Flow 1: Elapsed Day', 'sublabel' => 'Elapsed Day', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],

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

                // Flow 1 Group (Not the Origin) - Default: NOT Included (opt-in)
                'flow1_office'       => ['label' => 'Flow 1: Office Name', 'sublabel' => 'Office Name', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_received'     => ['label' => 'Flow 1: Received', 'sublabel' => 'Received', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_released'     => ['label' => 'Flow 1: Released', 'sublabel' => 'Released', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_elapsed_days' => ['label' => 'Flow 1: Elapsed Day', 'sublabel' => 'Elapsed Day', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],

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

                // Flow 1 Group (Not the Origin) - Default: NOT Included (opt-in)
                'flow1_office'       => ['label' => 'Flow 1: Office Name', 'sublabel' => 'Office Name', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_received'     => ['label' => 'Flow 1: Received', 'sublabel' => 'Received', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_released'     => ['label' => 'Flow 1: Released', 'sublabel' => 'Released', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],
                'flow1_elapsed_days' => ['label' => 'Flow 1: Elapsed Day', 'sublabel' => 'Elapsed Day', 'default' => false, 'group' => 'flow1', 'flow_title' => 'Flow 1 (not the origin)'],

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
            $latestLog = $logs->last();

            $remarks = $latestLog && !empty($latestLog->notes) ? $latestLog->notes : '-';
            $receivedBy = $latestLog && $latestLog->first_name ? ($latestLog->first_name . ' ' . $latestLog->last_name) : '-';

            // Extract Flow 1 (First destination office step that is NOT the origin)
            $originOfficeCode = $t->originated_from ?: ($firstLog?->office_code ?? '');
            $nonOriginLogs = $logs->filter(function ($l) use ($originOfficeCode) {
                return !empty($l->office_code) && $l->office_code !== $originOfficeCode;
            })->values();

            // Flow 1 Log (Step 1 after origin)
            $flow1Log = $nonOriginLogs->get(0) ?? ($logs->count() > 1 ? $logs->get(1) : null);
            
            // Flow 1 Office Name & Code
            $flow1Office = '-';
            $flow1OfficeCode = '';
            if ($flow1Log) {
                $flow1Office = $flow1Log->office_name ?: $flow1Log->office_code;
                $flow1OfficeCode = $flow1Log->office_code ?: '';
            }

            // Flow 1 Received Date/Time & Received By
            $flow1RecDate = '-';
            $flow1RecBy = '-';
            if ($flow1Log && $flow1Log->date_in) {
                $flow1RecDate = Carbon::parse($flow1Log->date_in)->format('Y-m-d h:i A');
                $flow1RecBy = ($flow1Log->first_name || $flow1Log->last_name) 
                    ? trim($flow1Log->first_name . ' ' . $flow1Log->last_name) 
                    : 'Received';
            } elseif ($flow1Log) {
                $flow1RecDate = 'Pending Receive';
            }

            // Flow 1 Released Date/Time & Released By
            $flow1RelDate = '-';
            $flow1RelBy = '-';
            if ($flow1Log && $flow1Log->date_out) {
                $flow1RelDate = Carbon::parse($flow1Log->date_out)->format('Y-m-d h:i A');
                $flow1RelBy = ($flow1Log->first_name || $flow1Log->last_name) 
                    ? trim($flow1Log->first_name . ' ' . $flow1Log->last_name) 
                    : 'Released';
            } elseif ($flow1Log && $flow1Log->date_in) {
                $flow1RelDate = 'In Progress';
            }

            // Flow 1 Elapsed Days (only counts the elapsed days)
            $flow1ElapsedDays = '-';
            if ($flow1Log && $flow1Log->date_in) {
                $start = Carbon::parse($flow1Log->date_in);
                $end = $flow1Log->date_out ? Carbon::parse($flow1Log->date_out) : now();
                $diffDays = (int) abs($start->diffInDays($end));
                $flow1ElapsedDays = $diffDays . ' day' . ($diffDays === 1 ? '' : 's');
            }

            // Flow 1 formatted text for CSV
            $flow1RecText = ($flow1RecDate !== '-' && $flow1RecDate !== 'Pending Receive')
                ? ($flow1RecDate . ($flow1RecBy !== '-' ? ' (' . $flow1RecBy . ')' : ''))
                : $flow1RecDate;

            $flow1RelText = ($flow1RelDate !== '-' && $flow1RelDate !== 'In Progress')
                ? ($flow1RelDate . ($flow1RelBy !== '-' ? ' (' . $flow1RelBy . ')' : ''))
                : $flow1RelDate;

            // Flow 2 Log (Step 2 after origin if present)
            $flow2Log = $nonOriginLogs->get(1) ?? ($logs->count() > 2 ? $logs->get(2) : null);
            $flow2Office = $flow2Log ? ($flow2Log->office_name ?: $flow2Log->office_code) : '-';
            $flow2OfficeCode = $flow2Log?->office_code ?: '';

            $flow2RecDate = '-';
            $flow2RecBy = '-';
            if ($flow2Log && $flow2Log->date_in) {
                $flow2RecDate = Carbon::parse($flow2Log->date_in)->format('Y-m-d h:i A');
                $flow2RecBy = ($flow2Log->first_name || $flow2Log->last_name) 
                    ? trim($flow2Log->first_name . ' ' . $flow2Log->last_name) 
                    : 'Received';
            } elseif ($flow2Log) {
                $flow2RecDate = 'Pending Receive';
            }

            $flow2RelDate = '-';
            $flow2RelBy = '-';
            if ($flow2Log && $flow2Log->date_out) {
                $flow2RelDate = Carbon::parse($flow2Log->date_out)->format('Y-m-d h:i A');
                $flow2RelBy = ($flow2Log->first_name || $flow2Log->last_name) 
                    ? trim($flow2Log->first_name . ' ' . $flow2Log->last_name) 
                    : 'Released';
            } elseif ($flow2Log && $flow2Log->date_in) {
                $flow2RelDate = 'In Progress';
            }

            $flow2ElapsedDays = '-';
            if ($flow2Log && $flow2Log->date_in) {
                $start2 = Carbon::parse($flow2Log->date_in);
                $end2 = $flow2Log->date_out ? Carbon::parse($flow2Log->date_out) : now();
                $diffDays2 = (int) abs($start2->diffInDays($end2));
                $flow2ElapsedDays = $diffDays2 . ' day' . ($diffDays2 === 1 ? '' : 's');
            }

            $flow2RecText = ($flow2RecDate !== '-' && $flow2RecDate !== 'Pending Receive')
                ? ($flow2RecDate . ($flow2RecBy !== '-' ? ' (' . $flow2RecBy . ')' : ''))
                : $flow2RecDate;

            $flow2RelText = ($flow2RelDate !== '-' && $flow2RelDate !== 'In Progress')
                ? ($flow2RelDate . ($flow2RelBy !== '-' ? ' (' . $flow2RelBy . ')' : ''))
                : $flow2RelDate;

            // Legacy Step 1 / Step 2 values for backwards compatibility
            $step1Received = $firstLog && $firstLog->date_in ? Carbon::parse($firstLog->date_in)->format('Y-m-d H:i') : ($t->date_created ? Carbon::parse($t->date_created)->format('Y-m-d H:i') : '-');
            $step1Released = $firstLog && $firstLog->date_out ? Carbon::parse($firstLog->date_out)->format('Y-m-d H:i') : ($logs->count() > 1 ? Carbon::parse($firstLog?->date_in ?? $t->date_created)->format('Y-m-d H:i') : '-');
            $step2Received = $flow1RecDate;
            $step2Released = $flow1RelDate;

            // Released / Received date/time/by for Application Letters
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

            $rows[] = [
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

                // Flow 1 Columns (Not the Origin)
                'flow1_office'       => $flow1Office,
                'flow1_office_code'  => $flow1OfficeCode,
                'flow1_received'     => $flow1RecText,
                'flow1_received_date'=> $flow1RecDate,
                'flow1_received_by'  => $flow1RecBy,
                'flow1_released'     => $flow1RelText,
                'flow1_released_date'=> $flow1RelDate,
                'flow1_released_by'  => $flow1RelBy,
                'flow1_elapsed_days' => $flow1ElapsedDays,

                // Flow 2 Columns
                'flow2_office'       => $flow2Office,
                'flow2_office_code'  => $flow2OfficeCode,
                'flow2_received'     => $flow2RecText,
                'flow2_received_date'=> $flow2RecDate,
                'flow2_received_by'  => $flow2RecBy,
                'flow2_released'     => $flow2RelText,
                'flow2_released_date'=> $flow2RelDate,
                'flow2_released_by'  => $flow2RelBy,
                'flow2_elapsed_days' => $flow2ElapsedDays,

                // Legacy Step aliases
                'step1_received'     => $step1Received,
                'step1_released'     => $step1Released,
                'step2_received'     => $step2Received,
                'step2_released'     => $step2Released,

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
