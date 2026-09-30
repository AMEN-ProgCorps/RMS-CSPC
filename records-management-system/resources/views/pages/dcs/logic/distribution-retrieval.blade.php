<?php

namespace App\Helpers;

use App\Services\DcsNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistributionRetrievalHelper
{
    public const DIST_PENDING = 'pending_pickup';

    public const DIST_DISTRIBUTED = 'distributed';

    public const RET_PENDING = 'pending_retrieval';

    public const RET_RETRIEVED = 'retrieved';

    public const RET_NA = 'n_a';

    public const DNR_ROW_PREVIEW = 5;

    /** @return array<string, string> */
    public static function docTypeFilterOptions(): array
    {
        return array_merge(['all' => 'All'], OfficeIntakeHelper::documentGroupDefs());
    }

    public static function schemaReady(): bool
    {
        return Schema::hasTable('dcs_distribution_offices')
            && Schema::hasColumn('dcs_distribution_offices', 'distribution_status');
    }

    public static function distributionStatusLabel(string $status): string
    {
        return match ($status) {
            self::DIST_DISTRIBUTED => 'Distributed (Handover Complete)',
            default => 'Pending Pickup (Awaiting Recipient)',
        };
    }

    public static function copyRetrievalStatusLabel(string $status): string
    {
        return match ($status) {
            self::RET_RETRIEVED => 'Retrieved (Return Complete)',
            self::RET_PENDING => 'Pending Retrieval (Unreturned Copy)',
            default => 'N/A (Initial New Document Issue)',
        };
    }

    public static function defaultCopyRetrievalStatus(int $requestId, int $officeId): string
    {
        if ($requestId < 1 || $officeId < 1 || ! Schema::hasTable('dcs_retrieval_offices')) {
            return self::RET_NA;
        }

        $row = DB::table('dcs_document_retrieval as ret')
            ->join('dcs_retrieval_offices as ro', 'ro.retrieval_id', '=', 'ret.id')
            ->where('ret.request_id', $requestId)
            ->where('ro.office_id', $officeId)
            ->select('ro.retrieval_status')
            ->first();

        if (! $row) {
            return self::RET_NA;
        }

        $status = strtolower(trim((string) ($row->retrieval_status ?? 'pending')));

        return $status === 'retrieved' ? self::RET_RETRIEVED : self::RET_PENDING;
    }

    public static function syncCopyRetrievalFromRetrieval(int $distributionId, int $requestId): void
    {
        if (! self::schemaReady() || $distributionId < 1 || $requestId < 1) {
            return;
        }

        $offices = DB::table('dcs_distribution_offices')
            ->where('distribution_id', $distributionId)
            ->get(['id', 'office_id', 'copy_retrieval_status', 'distribution_status']);

        foreach ($offices as $office) {
            $next = self::defaultCopyRetrievalStatus($requestId, (int) $office->office_id);
            $updates = ['copy_retrieval_status' => $next];
            if ($next === self::RET_PENDING
                && (string) ($office->distribution_status ?? '') === self::DIST_DISTRIBUTED) {
                $updates['distribution_status'] = self::DIST_PENDING;
            }
            DB::table('dcs_distribution_offices')->where('id', $office->id)->update($updates);
        }
    }

    /** Sync every distribution row for a request after retrieval section changes. */
    public static function syncAllDistributionsForRequest(int $requestId): void
    {
        if ($requestId < 1 || ! Schema::hasTable('dcs_document_distribution')) {
            return;
        }

        $rows = DB::table('dcs_document_distribution')->where('request_id', $requestId)->get(['id']);
        foreach ($rows as $row) {
            self::syncCopyRetrievalFromRetrieval((int) $row->id, $requestId);
        }
    }

    /**
     * @param  'all'|string  $docTypeFilter  internal_docs, external_docs, …
     */
    protected static function applyDocTypeFilter($query, string $docTypeFilter): void
    {
        if ($docTypeFilter === '' || $docTypeFilter === 'all') {
            return;
        }

        $parentId = RegisterQueryHelper::parentTypeIdMap()[$docTypeFilter] ?? null;
        if (! $parentId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($q) use ($parentId) {
            $q->where('dr.doc_type_id', (int) $parentId)
                ->orWhereExists(function ($sub) use ($parentId) {
                    $sub->select(DB::raw(1))
                        ->from('dcs_doc_types as st')
                        ->whereColumn('st.id', 'dr.sub_type_id')
                        ->where('st.parent_id', (int) $parentId);
                })
                ->orWhereExists(function ($sub) use ($parentId) {
                    $sub->select(DB::raw(1))
                        ->from('dcs_doc_types as mt')
                        ->whereColumn('mt.id', 'ml.doc_type_id')
                        ->where('mt.parent_id', (int) $parentId);
                })
                ->orWhere('ml.doc_type_id', (int) $parentId);
        });
    }

    protected static function mapOfficeRow(object $row, array $ackNames): array
    {
        $distStatus = (string) ($row->distribution_status ?? self::DIST_PENDING);
        if ($distStatus === '' && ! empty($row->office_received_at)) {
            $distStatus = self::DIST_DISTRIBUTED;
        }
        if ($distStatus === '') {
            $distStatus = self::DIST_PENDING;
        }
        $retStatus = (string) ($row->copy_retrieval_status ?? self::RET_NA);
        if ($retStatus === '') {
            $retStatus = self::RET_NA;
        }
        $ackBy = (int) ($row->client_acknowledged_by ?? 0);
        $pendingVerify = (bool) ($row->pending_admin_verification ?? false);

        return [
            'distribution_office_id' => (int) $row->id,
            'office_id' => (int) $row->office_id,
            'office_name' => trim((string) ($row->office_name ?? '')) ?: (string) ($row->office_code ?? 'Office'),
            'office_code' => (string) ($row->office_code ?? ''),
            'copies' => (int) ($row->copies ?? 1),
            'distribution_status' => $distStatus,
            'distribution_status_label' => self::distributionStatusLabel($distStatus),
            'copy_retrieval_status' => $retStatus,
            'copy_retrieval_status_label' => self::copyRetrievalStatusLabel($retStatus),
            'wet_signature_verified' => (bool) ($row->wet_signature_verified ?? false),
            'pending_admin_verification' => $pendingVerify,
            'needs_verify' => $pendingVerify && $distStatus !== self::DIST_DISTRIBUTED,
            'client_acknowledged_at' => $row->client_acknowledged_at ?? null,
            'client_acknowledged_by' => $ackBy,
            'client_acknowledged_by_name' => $ackBy > 0 ? ($ackNames[$ackBy] ?? 'Staff') : '',
            'can_distribute' => $retStatus !== self::RET_PENDING,
        ];
    }

    /**
     * Grouped tracking cards (one card per document number family, multiple revisions inside).
     *
     * @param  'all'|string  $docTypeFilter
     * @return list<array>
     */
    public static function monitoringCards(
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $docTypeFilter = 'all',
        string $searchQuery = ''
    ): array {
        if (! Schema::hasTable('dcs_document_distribution') || $docTypeFilter === '') {
            return [];
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $query = DB::table('dcs_document_distribution as dist')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'dist.request_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->select([
                'dist.id as distribution_id',
                'dist.request_id',
                'dist.created_at as dist_created_at',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'ml.effectivity_date',
                'dr.doc_type_id',
                'dr.sub_type_id',
            ]);

        RegisterQueryHelper::applyNotDeleted($query, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($query, 'dr');
        self::applyDocTypeFilter($query, $docTypeFilter);

        if ($dateFrom) {
            $query->whereDate('dist.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('dist.created_at', '<=', $dateTo);
        }

        $dists = $query
            ->orderByDesc('dist.created_at')
            ->orderByDesc('dist.id')
            ->get();

        if ($dists->isEmpty()) {
            return [];
        }

        $distIds = $dists->pluck('distribution_id')->map(fn ($id) => (int) $id)->all();
        $officeCols = [
            'doff.id',
            'doff.distribution_id',
            'doff.office_id',
            'doff.copies',
            'doff.sort_order',
            'o.office_name',
            'o.office_code',
        ];
        foreach ([
            'distribution_status',
            'copy_retrieval_status',
            'wet_signature_verified',
            'office_received_at',
            'office_received_by',
            'client_acknowledged_at',
            'client_acknowledged_by',
            'pending_admin_verification',
        ] as $col) {
            if (Schema::hasColumn('dcs_distribution_offices', $col)) {
                $officeCols[] = 'doff.'.$col;
            }
        }

        $officeRows = DB::table('dcs_distribution_offices as doff')
            ->leftJoin($officeTbl.' as o', 'o.id', '=', 'doff.office_id')
            ->whereIn('doff.distribution_id', $distIds)
            ->orderBy('doff.sort_order')
            ->orderBy('doff.id')
            ->get($officeCols)
            ->groupBy(fn ($r) => (int) $r->distribution_id);

        $ackUserIds = $officeRows->flatten(1)
            ->pluck('client_acknowledged_by')
            ->filter(fn ($id) => $id !== null && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $ackNames = self::displayNamesForUsers($ackUserIds);

        $search = mb_strtolower(trim($searchQuery));
        $grouped = [];

        foreach ($dists as $dist) {
            $distId = (int) $dist->distribution_id;
            $offices = [];
            foreach ($officeRows->get($distId, collect()) as $row) {
                $offices[] = self::mapOfficeRow($row, $ackNames);
            }
            if ($offices === []) {
                continue;
            }

            $docNo = trim((string) ($dist->doc_no ?? ''));
            $docTitle = trim((string) ($dist->doc_title ?? ''));
            $cardKey = $docNo !== '' ? mb_strtolower($docNo) : 'req-'.(int) $dist->request_id;

            $revision = [
                'request_id' => (int) $dist->request_id,
                'distribution_id' => $distId,
                'revise_no' => (int) ($dist->revise_no ?? 0),
                'effectivity_date' => $dist->effectivity_date
                    ? RegisterQueryHelper::formatSmartDate($dist->effectivity_date)
                    : null,
                'offices' => $offices,
            ];

            if (! isset($grouped[$cardKey])) {
                $grouped[$cardKey] = [
                    'card_key' => $cardKey,
                    'doc_no' => $docNo,
                    'doc_title' => $docTitle,
                    'doc_type_filter' => self::resolveDocTypeFilterKey($dist),
                    'revisions' => [],
                ];
            }
            $grouped[$cardKey]['revisions'][] = $revision;
        }

        $cards = [];
        foreach ($grouped as $group) {
            usort($group['revisions'], static fn ($a, $b) => ($b['revise_no'] <=> $a['revise_no']) ?: ($b['request_id'] <=> $a['request_id']));
            $latest = $group['revisions'][0];
            $cards[] = [
                'card_key' => $group['card_key'],
                'doc_no' => $group['doc_no'],
                'doc_title' => $group['doc_title'],
                'doc_type_filter' => $group['doc_type_filter'],
                'revisions' => $group['revisions'],
                'request_id' => $latest['request_id'],
                'distribution_id' => $latest['distribution_id'],
                'revise_no' => $latest['revise_no'],
                'effectivity_date' => $latest['effectivity_date'],
                'offices' => $latest['offices'],
            ];
        }

        usort($cards, static fn ($a, $b) => strcmp($a['doc_no'] ?: $a['doc_title'], $b['doc_no'] ?: $b['doc_title']));

        if ($search !== '') {
            $cards = array_values(array_filter($cards, static function (array $card) use ($search) {
                $hay = mb_strtolower(
                    trim((string) ($card['doc_no'] ?? '')).' '
                    .trim((string) ($card['doc_title'] ?? ''))
                );

                return $hay !== ' ' && str_contains($hay, $search);
            }));
        }

        return $cards;
    }

    protected static function resolveDocTypeFilterKey(object $dist): string
    {
        $parentMap = RegisterQueryHelper::parentTypeIdMap();
        $docTypeId = (int) ($dist->doc_type_id ?? 0);
        $subTypeId = (int) ($dist->sub_type_id ?? 0);

        foreach ($parentMap as $key => $parentId) {
            if ((int) $parentId === $docTypeId) {
                return $key;
            }
        }
        if ($subTypeId > 0) {
            $parentId = DB::table('dcs_doc_types')->where('id', $subTypeId)->value('parent_id');
            foreach ($parentMap as $key => $mapParent) {
                if ((int) $mapParent === (int) $parentId) {
                    return $key;
                }
            }
        }

        return 'all';
    }

    /**
     * @return list<array{distribution_office_id:int,distribution_id:int,card_key:string,request_id:int,message:string}>
     */
    public static function verificationAlerts(): array
    {
        if (! self::schemaReady()
            || ! Schema::hasColumn('dcs_distribution_offices', 'pending_admin_verification')) {
            return [];
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $rows = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'dist.request_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->leftJoin($officeTbl.' as o', 'o.id', '=', 'doff.office_id')
            ->where('doff.pending_admin_verification', true)
            ->where(function ($q) {
                $q->whereNull('doff.distribution_status')
                    ->orWhere('doff.distribution_status', '!=', self::DIST_DISTRIBUTED);
            });

        RegisterQueryHelper::applyNotDeleted($rows, 'dr');
        RegisterQueryHelper::applyRegisteredDocumentScope($rows, 'dr');

        $rows = $rows
            ->orderByDesc('doff.client_acknowledged_at')
            ->orderByDesc('doff.id')
            ->get([
                'doff.id',
                'doff.copies',
                'doff.client_acknowledged_by',
                'dist.id as distribution_id',
                'dist.request_id',
                'o.office_name',
                'o.office_code',
                'ml.doc_title',
                'ml.doc_no',
                'ml.revise_no',
            ]);

        $names = self::displayNamesForUsers(
            $rows->pluck('client_acknowledged_by')->filter()->map(fn ($id) => (int) $id)->unique()->all()
        );

        $alerts = [];
        foreach ($rows as $row) {
            $staffId = (int) ($row->client_acknowledged_by ?? 0);
            $staff = $staffId > 0 ? ($names[$staffId] ?? 'Staff') : 'Staff';
            $office = trim((string) ($row->office_name ?? '')) ?: (string) ($row->office_code ?? 'Office');
            $title = trim((string) ($row->doc_title ?? ''));
            $docNo = trim((string) ($row->doc_no ?? ''));
            $revNo = isset($row->revise_no) ? (int) $row->revise_no : null;
            $cardKey = $docNo !== '' ? mb_strtolower($docNo) : 'req-'.(int) $row->request_id;
            $receiptLabel = self::formatReceiptDocLabel($title, $docNo, $revNo);
            $alerts[] = [
                'distribution_office_id' => (int) $row->id,
                'distribution_id' => (int) $row->distribution_id,
                'request_id' => (int) $row->request_id,
                'card_key' => $cardKey,
                'message' => "Verification Required: {$staff} ({$office}) acknowledged receipt of {$receiptLabel}. "
                    .'Please verify the wet signature on the printed D&R List Form before updating the distribution status.',
            ];
        }

        return $alerts;
    }

    /** Doc Title, Rev. No. label for alerts / admin notifications. */
    public static function formatReceiptDocLabel(string $docTitle, ?string $docNo = null, ?int $revNo = null): string
    {
        $title = trim($docTitle);
        if ($title === '') {
            $title = trim((string) $docNo);
        }
        if ($title === '') {
            $title = 'document';
        }
        $rev = $revNo !== null ? (int) $revNo : 0;

        return "{$title}, Rev. {$rev}";
    }

    public static function monitoringDeepLinkUrl(?int $distributionOfficeId = null): string
    {
        $url = '/dcs/reports/monitoring?dnr=1&dtype=all';
        if ($distributionOfficeId && $distributionOfficeId > 0) {
            $url .= '&verify='.$distributionOfficeId;
        }

        return $url;
    }

    /** Admin notification link — opens DnR monitoring (optional verify scroll). */
    public static function monitoringNotificationUrl(?int $distributionOfficeId = null): string
    {
        return self::monitoringDeepLinkUrl($distributionOfficeId);
    }

    /**
     * @return array{ok:bool,message:string}
     */
    public static function updateOfficeStatus(
        int $distributionOfficeId,
        string $distributionStatus,
        string $copyRetrievalStatus,
        bool $wetSignatureVerified
    ): array {
        if (! self::schemaReady() || $distributionOfficeId < 1) {
            return ['ok' => false, 'message' => 'Distribution & Retrieval monitoring is not available.'];
        }

        $row = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->where('doff.id', $distributionOfficeId)
            ->select('doff.*', 'dist.request_id')
            ->first();

        if (! $row) {
            return ['ok' => false, 'message' => 'Distribution office row not found.'];
        }

        $distributionStatus = $distributionStatus === self::DIST_DISTRIBUTED
            ? self::DIST_DISTRIBUTED
            : self::DIST_PENDING;

        $copyRetrievalStatus = match ($copyRetrievalStatus) {
            self::RET_RETRIEVED => self::RET_RETRIEVED,
            self::RET_PENDING => self::RET_PENDING,
            default => self::RET_NA,
        };

        if ($distributionStatus === self::DIST_DISTRIBUTED && $copyRetrievalStatus === self::RET_PENDING) {
            return [
                'ok' => false,
                'message' => 'Retrieve the old document copy first before marking distribution as Distributed.',
            ];
        }

        if ($distributionStatus === self::DIST_DISTRIBUTED && ! $wetSignatureVerified) {
            return [
                'ok' => false,
                'message' => 'Confirm Physical Wet Signature Verified before marking as Distributed.',
            ];
        }

        $userId = (int) (auth()->id() ?? 0);
        $updates = [
            'distribution_status' => $distributionStatus,
            'copy_retrieval_status' => $copyRetrievalStatus,
            'wet_signature_verified' => $wetSignatureVerified,
        ];

        if ($wetSignatureVerified) {
            $updates['wet_signature_verified_at'] = now();
            $updates['wet_signature_verified_by'] = $userId > 0 ? $userId : null;
        }

        if ($distributionStatus === self::DIST_DISTRIBUTED) {
            $updates['pending_admin_verification'] = false;
            if (Schema::hasColumn('dcs_distribution_offices', 'office_received_at')
                && empty($row->office_received_at)) {
                $updates['office_received_at'] = now();
                $updates['office_received_by'] = $userId > 0 ? $userId : null;
            }
        }

        DB::table('dcs_distribution_offices')->where('id', $distributionOfficeId)->update($updates);

        if ($copyRetrievalStatus !== self::RET_NA
            && Schema::hasTable('dcs_retrieval_offices')
            && Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')) {
            $retrievalId = DB::table('dcs_document_retrieval')
                ->where('request_id', (int) $row->request_id)
                ->value('id');
            if ($retrievalId) {
                $retStatus = $copyRetrievalStatus === self::RET_RETRIEVED ? 'retrieved' : 'pending';
                DB::table('dcs_retrieval_offices')
                    ->where('retrieval_id', (int) $retrievalId)
                    ->where('office_id', (int) $row->office_id)
                    ->update(['retrieval_status' => $retStatus]);
            }
        }

        return ['ok' => true, 'message' => 'Distribution & retrieval status updated.'];
    }

    /**
     * @return array{ok:bool,already?:bool,message:string}
     */
    public static function acknowledgeReceipt(int $requestId): array
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        $officeId = RegisterQueryHelper::currentOfficeId();
        if (! $officeId || $requestId <= 0) {
            return ['ok' => false, 'message' => 'Your office is not assigned to this document.'];
        }

        $select = ['doff.id', 'doff.copies'];
        foreach ([
            'distribution_status',
            'pending_admin_verification',
            'client_acknowledged_at',
            'client_acknowledged_by',
            'office_received_at',
            'office_received_by',
        ] as $col) {
            if (Schema::hasColumn('dcs_distribution_offices', $col)) {
                $select[] = 'doff.'.$col;
            }
        }

        $row = DB::table('dcs_document_distribution as dist')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->where('dist.request_id', $requestId)
            ->where('doff.office_id', $officeId)
            ->select($select)
            ->first();

        if (! $row) {
            return ['ok' => false, 'message' => 'This document is not listed for your office.'];
        }

        $ml = Schema::hasTable('dcs_masterlist_registration')
            ? DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first(['doc_title', 'doc_no', 'revise_no'])
            : null;
        $title = trim((string) ($ml->doc_title ?? ''));
        $docNo = trim((string) ($ml->doc_no ?? ''));
        $revNo = isset($ml->revise_no) ? (int) $ml->revise_no : null;
        $copyNo = max(1, (int) ($row->copies ?? 1));
        $distributionOfficeId = (int) $row->id;

        $distStatus = (string) ($row->distribution_status ?? '');
        if ($distStatus === self::DIST_DISTRIBUTED || ! empty($row->office_received_at ?? null)) {
            return [
                'ok' => true,
                'already' => true,
                'message' => 'This document is already marked Distributed on the Records Office side.',
            ];
        }

        if (! empty($row->pending_admin_verification) || ! empty($row->client_acknowledged_at ?? null)) {
            return [
                'ok' => true,
                'already' => true,
                'message' => 'Acknowledgement already sent. Waiting for Records Office wet-signature verification.',
            ];
        }

        $userId = (int) (auth()->id() ?? 0);
        $updates = [];
        if (Schema::hasColumn('dcs_distribution_offices', 'client_acknowledged_at')) {
            $updates['client_acknowledged_at'] = now();
            $updates['client_acknowledged_by'] = $userId > 0 ? $userId : null;
        }
        if (Schema::hasColumn('dcs_distribution_offices', 'pending_admin_verification')) {
            $updates['pending_admin_verification'] = true;
        }
        if ($updates !== []) {
            DB::table('dcs_distribution_offices')->where('id', $row->id)->update($updates);
        }

        $receiverName = RegisterQueryHelper::currentUserDisplayName();
        $officeName = RegisterQueryHelper::currentOfficeName();

        DcsNotificationService::notifyAdminClientAcknowledgedReceipt(
            $officeName,
            $receiverName,
            $title !== '' ? $title : $docNo,
            $docNo !== '' ? $docNo : null,
            $requestId,
            $revNo,
            $copyNo,
            $distributionOfficeId
        );

        return [
            'ok' => true,
            'already' => false,
            'message' => 'Acknowledgement sent. Status is pending Records Office verification.',
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    protected static function displayNamesForUsers(array $userIds): array
    {
        $userIds = array_values(array_filter($userIds, static fn (int $id) => $id > 0));
        if ($userIds === []) {
            return [];
        }

        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        return DB::table($accDetailsTbl)
            ->whereIn('account_id', $userIds)
            ->get(['account_id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($d) => [
                (int) $d->account_id => trim(trim((string) ($d->first_name ?? '')).' '.trim((string) ($d->last_name ?? ''))),
            ])
            ->all();
    }
}
