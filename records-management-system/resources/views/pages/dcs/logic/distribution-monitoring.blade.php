<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campus-wide Distribution and Retrieval monitor for Admin DCS.
 * One family per document number, with every revision and every office it was sent to.
 */
class DistributionRetrievalMonitorHelper
{
    /**
     * @return array{
     *     families: list<array<string, mixed>>,
     *     summary: array{documents: int, received: int, awaiting: int, retrieved: int, previous_out: int}
     * }
     */
    public static function monitor(string $search = '', string $status = 'all'): array
    {
        $empty = [
            'families' => [],
            'summary' => [
                'documents' => 0,
                'received' => 0,
                'awaiting' => 0,
                'retrieved' => 0,
                'previous_out' => 0,
            ],
        ];

        if (! Schema::hasTable('dcs_masterlist_registration')
            || ! Schema::hasTable('dcs_document_distribution')
            || ! Schema::hasTable('dcs_distribution_offices')) {
            return $empty;
        }

        $rows = self::loadRows();
        $families = self::buildFamilies($rows);
        $families = self::filterSearch($families, $search);
        $summary = self::summarize($families);
        $families = self::filterStatus($families, $status);

        return [
            'families' => $families,
            'summary' => $summary,
        ];
    }

    /** @return list<object> */
    private static function loadRows(): array
    {
        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $hasReceived = Schema::hasColumn('dcs_distribution_offices', 'office_received_at');
        $hasReceivedBy = Schema::hasColumn('dcs_distribution_offices', 'office_received_by');
        $hasRet = Schema::hasTable('dcs_document_retrieval') && Schema::hasTable('dcs_retrieval_offices');
        $hasRetStatus = $hasRet && Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status');
        $hasRetDate = $hasRet && Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date');
        $hasRevStatus = Schema::hasColumn('dcs_masterlist_registration', 'revision_status');
        $hasEffectivity = Schema::hasColumn('dcs_masterlist_registration', 'effectivity_date');
        $hasDistDate = Schema::hasColumn('dcs_distribution_offices', 'distribution_date');
        $hasStack = Schema::hasColumn('dcs_masterlist_registration', 'stack_group');
        $hasAllows = Schema::hasColumn('dcs_masterlist_registration', 'allows_revision');

        $select = [
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'dist.doc_distribution_date_actual',
            'doff.office_id',
            'doff.copies',
            'o.office_code',
            'o.office_name',
        ];
        $hasTypes = Schema::hasTable('dcs_doc_types');
        if ($hasTypes) {
            $select[] = 'dt.doc_type_name';
            $select[] = 'rdt.doc_type_name as req_type_name';
            $select[] = 'st.doc_type_name as sub_type_name';
        }
        if ($hasRevStatus) {
            $select[] = 'ml.revision_status';
        }
        if ($hasStack) {
            $select[] = 'ml.stack_group';
        }
        if ($hasAllows) {
            $select[] = 'ml.allows_revision';
        }
        if ($hasEffectivity) {
            $select[] = 'ml.effectivity_date';
        }
        if ($hasDistDate) {
            $select[] = 'doff.distribution_date';
        }
        if ($hasReceived) {
            $select[] = 'doff.office_received_at';
        }
        if ($hasReceivedBy) {
            $select[] = 'doff.office_received_by';
        }
        if ($hasRetStatus) {
            $select[] = 'ret.retrieval_status';
        }
        if ($hasRetDate) {
            $select[] = 'ret.retrieval_date';
        }

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->join('dcs_document_distribution as dist', 'dist.request_id', '=', 'ml.request_id')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->leftJoin($officeTbl . ' as o', 'o.id', '=', 'doff.office_id');

        if ($hasTypes) {
            $query->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'ml.doc_type_id')
                ->leftJoin('dcs_doc_types as rdt', 'rdt.id', '=', 'dr.doc_type_id')
                ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id');
        }

        if ($hasRet) {
            $query->leftJoin('dcs_document_retrieval as dret', 'dret.request_id', '=', 'ml.request_id')
                ->leftJoin('dcs_retrieval_offices as ret', function ($join) {
                    $join->on('ret.retrieval_id', '=', 'dret.id')
                        ->on('ret.office_id', '=', 'doff.office_id');
                });
        }

        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($query, 'dr');
        RegisterQueryHelper::applyExcludeOfficeIntakeRequests($query, 'dr');

        return $query->get($select)->all();
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private static function buildFamilies(array $rows): array
    {
        $names = self::receiverNames($rows);
        $revisions = [];

        foreach ($rows as $row) {
            $requestId = (int) ($row->request_id ?? 0);
            $officeId = (int) ($row->office_id ?? 0);
            if ($requestId < 1 || $officeId < 1) {
                continue;
            }

            $docNo = trim((string) ($row->doc_no ?? ''));
            $title = trim((string) ($row->doc_title ?? ''));
            $stackGroup = property_exists($row, 'stack_group') ? trim((string) $row->stack_group) : '';
            $allowsRevision = ! property_exists($row, 'allows_revision') || self::isEnabled($row->allows_revision);
            if ($stackGroup !== '') {
                $familyKey = 'stack:' . mb_strtolower($stackGroup);
            } elseif (! $allowsRevision && $title !== '' && $docNo !== '') {
                $familyKey = 'copy:' . mb_strtolower($docNo) . '|' . mb_strtolower($title);
            } else {
                $familyKey = $docNo !== '' ? mb_strtolower($docNo) : 'request:' . $requestId;
            }
            $revKey = $familyKey . '|' . $requestId;

            if (! isset($revisions[$revKey])) {
                $revisions[$revKey] = [
                    'family_key' => $familyKey,
                    'request_id' => $requestId,
                    'doc_no' => $docNo !== '' ? $docNo : '—',
                    'doc_title' => trim((string) ($row->doc_title ?? '')),
                    'revise_no' => (int) ($row->revise_no ?? 0),
                    'revision_status' => strtolower(trim((string) ($row->revision_status ?? 'latest'))) ?: 'latest',
                    'effectivity' => self::formatDate(property_exists($row, 'effectivity_date') ? $row->effectivity_date : null),
                    'effectivity_sort' => self::sortDate(property_exists($row, 'effectivity_date') ? $row->effectivity_date : null),
                    'distributed_on' => self::formatDate($row->doc_distribution_date_actual ?? ($row->distribution_date ?? null)),
                    'doc_type_name' => self::typeName($row),
                    'sub_type_name' => trim((string) ($row->sub_type_name ?? '')),
                    'stack_group' => $stackGroup,
                    'offices' => [],
                ];
            } elseif ($revisions[$revKey]['distributed_on'] === '' && ! empty($row->distribution_date ?? null)) {
                $revisions[$revKey]['distributed_on'] = self::formatDate($row->distribution_date);
            }

            $receivedAt = $row->office_received_at ?? null;
            $retrieved = strtolower(trim((string) ($row->retrieval_status ?? ''))) === 'retrieved';
            $office = $revisions[$revKey]['offices'][$officeId] ?? null;
            $next = [
                'office_id' => $officeId,
                'office_code' => trim((string) ($row->office_code ?? '')),
                'office_name' => trim((string) ($row->office_name ?? '')) ?: ('Office #' . $officeId),
                'copies' => $row->copies !== null && $row->copies !== '' ? (int) $row->copies : null,
                'received' => $receivedAt !== null && $receivedAt !== '',
                'received_at' => self::formatDateTime($receivedAt),
                'received_by' => $names[(int) ($row->office_received_by ?? 0)] ?? '',
                'retrieved' => $retrieved,
                'retrieved_on' => $retrieved ? self::formatDate($row->retrieval_date ?? null) : '',
            ];

            if ($office === null) {
                $revisions[$revKey]['offices'][$officeId] = $next;
                continue;
            }

            $office['received'] = $office['received'] || $next['received'];
            if ($office['received_at'] === '' && $next['received_at'] !== '') {
                $office['received_at'] = $next['received_at'];
                $office['received_by'] = $next['received_by'];
            }
            $office['retrieved'] = $office['retrieved'] || $next['retrieved'];
            if ($office['retrieved_on'] === '' && $next['retrieved_on'] !== '') {
                $office['retrieved_on'] = $next['retrieved_on'];
            }
            if ($office['copies'] === null && $next['copies'] !== null) {
                $office['copies'] = $next['copies'];
            }
            $revisions[$revKey]['offices'][$officeId] = $office;
        }

        $byFamily = [];
        foreach ($revisions as $revision) {
            $byFamily[$revision['family_key']][] = $revision;
        }

        $families = [];
        foreach ($byFamily as $familyRevs) {
            usort($familyRevs, function ($a, $b) {
                $rev = ((int) $b['revise_no']) <=> ((int) $a['revise_no']);
                if ($rev !== 0) {
                    return $rev;
                }
                $date = strcmp((string) ($b['effectivity_sort'] ?? ''), (string) ($a['effectivity_sort'] ?? ''));

                return $date !== 0 ? $date : ((int) $b['request_id']) <=> ((int) $a['request_id']);
            });

            $officeRevs = [];
            $maxRev = (int) ($familyRevs[0]['revise_no'] ?? 0);
            foreach ($familyRevs as $revision) {
                foreach ($revision['offices'] as $officeId => $office) {
                    $officeRevs[$officeId][] = [
                        'request_id' => $revision['request_id'],
                        'revise_no' => $revision['revise_no'],
                        'retrieved' => $office['retrieved'],
                    ];
                }
            }

            foreach ($familyRevs as &$revision) {
                foreach ($revision['offices'] as $officeId => &$office) {
                    $peers = $officeRevs[$officeId] ?? [];
                    $newerOut = (int) $revision['revise_no'] < $maxRev;
                    $hasOlder = false;
                    $olderStillOut = false;
                    foreach ($peers as $peer) {
                        if ((int) $peer['request_id'] === (int) $revision['request_id']) {
                            continue;
                        }
                        if ((int) $peer['revise_no'] < (int) $revision['revise_no'] && empty($peer['retrieved'])) {
                            $hasOlder = true;
                            $olderStillOut = true;
                        } elseif ((int) $peer['revise_no'] < (int) $revision['revise_no']) {
                            $hasOlder = true;
                        }
                    }
                    $office['old_copy'] = ! $hasOlder ? 'none' : ($olderStillOut ? 'pending' : 'retrieved');
                    $status = self::officeStatus($office['received'], $office['retrieved'], $newerOut, $olderStillOut);
                    $office['status'] = $status;
                    $office['status_label'] = self::statusLabel($status);
                    $office['status_short'] = self::statusShort($status);
                }
                unset($office);
                $revision['offices'] = array_values($revision['offices']);
                usort($revision['offices'], fn ($a, $b) => strcasecmp($a['office_name'], $b['office_name']));
            }
            unset($revision);

            $lead = $familyRevs[0];
            $families[] = [
                'doc_no' => $lead['doc_no'],
                'doc_title' => $lead['doc_title'] !== '' ? $lead['doc_title'] : 'Untitled',
                'latest_rev' => (int) $lead['revise_no'],
                'revisions' => $familyRevs,
            ];
        }

        usort($families, fn ($a, $b) => strnatcasecmp((string) $a['doc_no'], (string) $b['doc_no']));

        return $families;
    }

    private static function officeStatus(bool $received, bool $retrieved, bool $newerOut, bool $olderStillOut): string
    {
        if ($retrieved) {
            return 'retrieved';
        }
        if ($newerOut) {
            return 'still_out';
        }
        if ($olderStillOut) {
            return $received ? 'received_previous_out' : 'awaiting_previous_out';
        }

        return $received ? 'received' : 'awaiting';
    }

    public static function statusShort(string $status): string
    {
        return match ($status) {
            'retrieved' => 'Retrieved',
            'still_out' => 'Pending return',
            'received_previous_out', 'awaiting_previous_out' => 'Previous still out',
            'received' => 'Received',
            default => 'Awaiting',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'retrieved' => 'Retrieved',
            'still_out' => 'Pending return — not yet returned to Admin DCS',
            'received_previous_out' => 'Received — previous copy not retrieved',
            'awaiting_previous_out' => 'Not yet received — previous copy not retrieved',
            'received' => 'Received',
            default => 'Not yet received',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $families
     * @return list<array<string, mixed>>
     */
    private static function filterSearch(array $families, string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return $families;
        }
        $needle = mb_strtolower($search);

        return array_values(array_filter($families, function (array $family) use ($needle) {
            $hay = mb_strtolower($family['doc_no'] . ' ' . $family['doc_title']);
            if (str_contains($hay, $needle)) {
                return true;
            }
            foreach ($family['revisions'] as $revision) {
                $typeHay = mb_strtolower(trim((string) ($revision['doc_type_name'] ?? '')) . ' ' . trim((string) ($revision['sub_type_name'] ?? '')));
                if ($typeHay !== ' ' && str_contains($typeHay, $needle)) {
                    return true;
                }
                foreach ($revision['offices'] as $office) {
                    $officeHay = mb_strtolower($office['office_code'] . ' ' . $office['office_name']);
                    if (str_contains($officeHay, $needle)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $families
     * @return list<array<string, mixed>>
     */
    private static function filterStatus(array $families, string $status): array
    {
        $status = strtolower(trim($status));
        if ($status === '' || $status === 'all') {
            return $families;
        }

        $kept = [];
        foreach ($families as $family) {
            $revisions = [];
            foreach ($family['revisions'] as $revision) {
                $offices = array_values(array_filter(
                    $revision['offices'],
                    fn (array $office) => self::statusMatches($status, (string) $office['status'])
                ));
                if ($offices === []) {
                    continue;
                }
                $revision['offices'] = $offices;
                $revisions[] = $revision;
            }
            if ($revisions === []) {
                continue;
            }
            $family['revisions'] = $revisions;
            $kept[] = $family;
        }

        return $kept;
    }

    private static function statusMatches(string $filter, string $status): bool
    {
        return match ($filter) {
            'awaiting' => in_array($status, ['awaiting', 'awaiting_previous_out'], true),
            'received' => in_array($status, ['received', 'received_previous_out'], true),
            'retrieved' => $status === 'retrieved',
            'previous_out' => in_array($status, ['still_out', 'received_previous_out', 'awaiting_previous_out'], true),
            default => true,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $families
     * @return array{documents: int, received: int, awaiting: int, retrieved: int, previous_out: int}
     */
    private static function summarize(array $families): array
    {
        $summary = [
            'documents' => count($families),
            'received' => 0,
            'awaiting' => 0,
            'retrieved' => 0,
            'previous_out' => 0,
        ];

        foreach ($families as $family) {
            foreach ($family['revisions'] as $revision) {
                foreach ($revision['offices'] as $office) {
                    $status = (string) ($office['status'] ?? '');
                    if (self::statusMatches('received', $status)) {
                        $summary['received']++;
                    }
                    if (self::statusMatches('awaiting', $status)) {
                        $summary['awaiting']++;
                    }
                    if ($status === 'retrieved') {
                        $summary['retrieved']++;
                    }
                    if (self::statusMatches('previous_out', $status)) {
                        $summary['previous_out']++;
                    }
                }
            }
        }

        return $summary;
    }

    /** @param  list<object>  $rows */
    private static function receiverNames(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->office_received_by ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $table = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';
        $names = [];
        foreach (DB::table($table)->whereIn('account_id', array_values($ids))->get(['account_id', 'first_name', 'last_name']) as $row) {
            $names[(int) $row->account_id] = trim(trim((string) ($row->first_name ?? '')) . ' ' . trim((string) ($row->last_name ?? '')));
        }

        return $names;
    }

    private static function typeName(object $row): string
    {
        $name = trim((string) ($row->doc_type_name ?? ''));
        if ($name === '') {
            $name = trim((string) ($row->req_type_name ?? ''));
        }

        return $name !== '' ? $name : 'Unclassified';
    }

    private static function isEnabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'f', 'false', 'no'], true);
    }

    private static function sortDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function formatDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($value)->format('M d, Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function formatDateTime(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return \Carbon\Carbon::parse($value)->timezone('Asia/Manila')->format('M d, Y g:i A');
        } catch (\Throwable) {
            return '';
        }
    }
}
