<?php

namespace App\Helpers;

use App\Services\DcsNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistributionRetrievalHelper
{
    public const DIST_PENDING = 'pending_pickup';
    public const DIST_DISTRIBUTED = 'distributed';

    public const RET_PENDING = 'pending';
    public const RET_RETRIEVED = 'retrieved';
    public const RET_NA = 'na';

    public static function tablesReady(): bool
    {
        return Schema::hasTable('dcs_document_distribution')
            && Schema::hasTable('dcs_distribution_offices')
            && Schema::hasColumn('dcs_distribution_offices', 'distribution_status');
    }

    public static function copyLabel(?int $copyNo, int $copies = 1): string
    {
        $copyNo = (int) $copyNo;
        $copies = max(1, $copies);
        if ($copyNo < 1) {
            return 'Copy #—';
        }
        if ($copies === 1) {
            return 'Copy #' . $copyNo;
        }

        return 'Copy #' . $copyNo . '–' . ($copyNo + $copies - 1);
    }

    public static function distributionLabel(?string $status): string
    {
        return $status === self::DIST_DISTRIBUTED
            ? 'Distributed (Handover Complete)'
            : 'Pending Pickup (Awaiting Recipient)';
    }

    public static function retrievalLabel(?string $status): string
    {
        return match ($status) {
            self::RET_PENDING => 'Pending Retrieval (Unreturned Paper Copy)',
            self::RET_RETRIEVED => 'Retrieved & Stamped "OBSOLETE"',
            default => 'N/A (Initial New Document Issue)',
        };
    }

    public static function retrievalShort(?string $status): string
    {
        return match ($status) {
            self::RET_PENDING => 'Pending Retrieval',
            self::RET_RETRIEVED => 'Retrieved',
            default => 'N/A',
        };
    }

    public static function distributionShort(?string $status): string
    {
        return $status === self::DIST_DISTRIBUTED ? 'Distributed' : 'Pending Pickup';
    }

    /**
     * Preserve / seed D&R columns when distribution office rows are rewritten.
     *
     * @param  array<string, mixed>  $row
     * @param  object|null  $prior
     * @return array<string, mixed>
     */
    public static function mergeTrackingIntoRow(array $row, ?object $prior, int $distributionId, int $officeId): array
    {
        if (! self::tablesReady()) {
            return $row;
        }

        $isNewOffice = $prior === null;
        static $cols = null;
        if ($cols === null) {
            $cols = [];
            foreach ([
                'copy_no',
                'distribution_status',
                'copy_retrieval_status',
                'old_version_label',
                'physical_signature_verified',
                'physical_signature_verified_at',
                'physical_signature_verified_by',
                'client_acknowledged_at',
                'client_acknowledged_by',
                'verification_required',
            ] as $col) {
                $cols[$col] = Schema::hasColumn('dcs_distribution_offices', $col);
            }
        }
        $set = function (string $col, mixed $value) use (&$row, $cols): void {
            if (! empty($cols[$col])) {
                $row[$col] = $value;
            }
        };

        $set('copy_no', (int) ($prior->copy_no ?? 0));
        $set('distribution_status', $prior->distribution_status ?? self::DIST_PENDING);
        $set('copy_retrieval_status', $prior->copy_retrieval_status ?? self::RET_NA);
        $set('old_version_label', $prior->old_version_label ?? null);
        $set('physical_signature_verified', (bool) ($prior->physical_signature_verified ?? false));
        $set('physical_signature_verified_at', $prior->physical_signature_verified_at ?? null);
        $set('physical_signature_verified_by', $prior->physical_signature_verified_by ?? null);
        $set('client_acknowledged_at', $prior->client_acknowledged_at ?? null);
        $set('client_acknowledged_by', $prior->client_acknowledged_by ?? null);
        $set('verification_required', (bool) ($prior->verification_required ?? false));

        if ($isNewOffice) {
            $prev = self::previousVersionForOffice($distributionId, $officeId);
            if ($prev) {
                $set('copy_retrieval_status', self::RET_PENDING);
                $set('old_version_label', $prev);
            } else {
                $set('copy_retrieval_status', self::RET_NA);
                $set('old_version_label', null);
            }
            $set('distribution_status', self::DIST_PENDING);
            $set('physical_signature_verified', false);
            $set('physical_signature_verified_at', null);
            $set('physical_signature_verified_by', null);
            $set('client_acknowledged_at', null);
            $set('client_acknowledged_by', null);
            $set('verification_required', false);
        }

        return $row;
    }

    /** Sequential copy numbers per distribution, keeping existing numbers when present. */
    public static function assignCopyNumbers(int $distributionId): void
    {
        if (! self::tablesReady() || ! Schema::hasColumn('dcs_distribution_offices', 'copy_no')) {
            return;
        }

        $rows = DB::table('dcs_distribution_offices')
            ->where('distribution_id', $distributionId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'copies', 'copy_no']);

        $used = [];
        foreach ($rows as $row) {
            $n = (int) ($row->copy_no ?? 0);
            if ($n > 0) {
                $used[$n] = true;
            }
        }

        $next = 1;
        foreach ($rows as $row) {
            $copies = max(1, (int) ($row->copies ?? 1));
            $copyNo = (int) ($row->copy_no ?? 0);
            if ($copyNo < 1) {
                while (isset($used[$next])) {
                    $next++;
                }
                $copyNo = $next;
                DB::table('dcs_distribution_offices')->where('id', $row->id)->update(['copy_no' => $copyNo]);
            }
            for ($i = 0; $i < $copies; $i++) {
                $used[$copyNo + $i] = true;
            }
            $next = max($next, $copyNo + $copies);
        }
    }

    public static function previousVersionForOffice(int $distributionId, int $officeId): ?string
    {
        $dist = DB::table('dcs_document_distribution')->where('id', $distributionId)->first(['request_id']);
        $requestId = (int) ($dist->request_id ?? 0);
        if ($requestId < 1 || ! Schema::hasTable('dcs_masterlist_registration')) {
            return null;
        }

        $mlCols = ['doc_no', 'revise_no', 'doc_title'];
        if (Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            $mlCols[] = 'revised_from_doc_no';
        }
        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first($mlCols);
        if (! $ml) {
            return null;
        }

        $docNo = trim((string) ($ml->doc_no ?? ''));
        $fromDoc = trim((string) ($ml->revised_from_doc_no ?? ''));
        $revNo = (int) ($ml->revise_no ?? 0);
        $isRevision = $revNo > 0 || $fromDoc !== '';
        if (! $isRevision) {
            return null;
        }

        $lookupNos = array_values(array_unique(array_filter([$docNo, $fromDoc])));
        if ($lookupNos === []) {
            return null;
        }

        $prev = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_distribution as dist', 'dist.request_id', '=', 'ml.request_id')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->where('doff.office_id', $officeId)
            ->where('ml.request_id', '!=', $requestId)
            ->whereIn('ml.doc_no', $lookupNos)
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->first(['ml.doc_no', 'ml.revise_no', 'ml.doc_title']);

        if (! $prev) {
            return null;
        }

        $label = trim((string) ($prev->doc_no ?? ''));
        $prevRev = (int) ($prev->revise_no ?? 0);
        if ($label !== '') {
            $label .= $prevRev > 0 ? ' Rev ' . $prevRev : ' Rev 0';
        } else {
            $label = trim((string) ($prev->doc_title ?? 'previous copy'));
            if ($prevRev > 0) {
                $label .= ' (Rev ' . $prevRev . ')';
            }
        }

        return $label;
    }

    /**
     * Notify target offices after a document is registered or new offices are added.
     *
     * @param  list<int>  $onlyOfficeIds
     * @param  list<string>  $skipCodes
     */
    public static function notifyPickupForRequest(int $requestId, array $skipCodes = [], array $onlyOfficeIds = []): void
    {
        if ($requestId < 1 || ! self::tablesReady()) {
            return;
        }

        $skip = collect($skipCodes)
            ->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter()
            ->unique()
            ->all();

        $mlCols = ['doc_no', 'doc_title', 'revise_no'];
        if (Schema::hasTable('dcs_masterlist_registration')
            && Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            $mlCols[] = 'revised_from_doc_no';
        }
        $ml = Schema::hasTable('dcs_masterlist_registration')
            ? DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first($mlCols)
            : null;

        $title = trim((string) ($ml->doc_title ?? ''));
        $revNo = (int) ($ml->revise_no ?? 0);
        $fromDoc = trim((string) ($ml->revised_from_doc_no ?? ''));

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $query = DB::table('dcs_document_distribution as dist')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->join($officeTbl . ' as o', 'o.id', '=', 'doff.office_id')
            ->where('dist.request_id', $requestId)
            ->whereNotNull('o.office_code')
            ->where('o.office_code', '!=', '');

        if ($onlyOfficeIds !== []) {
            $query->whereIn('doff.office_id', $onlyOfficeIds);
        }

        $rows = $query->get([
            'o.office_code',
            'doff.copy_no',
            'doff.copies',
            'doff.copy_retrieval_status',
            'doff.old_version_label',
        ]);

        foreach ($rows as $row) {
            $code = trim((string) $row->office_code);
            if ($code === '' || in_array(strtoupper($code), $skip, true)) {
                continue;
            }
            $isRevision = $revNo > 0
                || $fromDoc !== ''
                || ($row->copy_retrieval_status ?? '') === self::RET_PENDING;
            DcsNotificationService::notifyDocumentPickupReady(
                $code,
                $title !== '' ? $title : trim((string) ($ml->doc_no ?? 'controlled document')),
                self::copyLabel((int) ($row->copy_no ?? 0), (int) ($row->copies ?? 1)),
                $isRevision,
                trim((string) ($row->old_version_label ?? '')) ?: ($fromDoc !== '' ? $fromDoc : ('Rev ' . max(0, $revNo - 1)))
            );
        }
    }

    /** Sync Document Retrieval office statuses onto the D&R tracking card. */
    public static function syncRetrievalStatusesToTracking(int $requestId): void
    {
        if ($requestId < 1 || ! self::tablesReady() || ! Schema::hasTable('dcs_retrieval_offices')) {
            return;
        }
        if (! Schema::hasColumn('dcs_distribution_offices', 'copy_retrieval_status')) {
            return;
        }

        $retrieval = DB::table('dcs_document_retrieval')->where('request_id', $requestId)->first(['id']);
        if (! $retrieval) {
            return;
        }

        $dist = DB::table('dcs_document_distribution')->where('request_id', $requestId)->first(['id']);
        if (! $dist) {
            return;
        }

        $rows = DB::table('dcs_retrieval_offices')
            ->where('retrieval_id', (int) $retrieval->id)
            ->get(['office_id', 'retrieval_status']);

        foreach ($rows as $row) {
            $officeId = (int) ($row->office_id ?? 0);
            if ($officeId < 1) {
                continue;
            }
            $status = strtolower(trim((string) ($row->retrieval_status ?? 'pending')));
            $mapped = $status === 'retrieved' ? self::RET_RETRIEVED : self::RET_PENDING;
            DB::table('dcs_distribution_offices')
                ->where('distribution_id', (int) $dist->id)
                ->where('office_id', $officeId)
                ->update(['copy_retrieval_status' => $mapped]);
        }
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function docTypeFilters(): array
    {
        return [
            ['key' => 'all', 'label' => 'All'],
            ['key' => 'internal_docs', 'label' => 'Internal'],
            ['key' => 'external_docs', 'label' => 'External'],
            ['key' => 'internal_forms', 'label' => 'Internal Forms'],
            ['key' => 'forms', 'label' => 'Forms'],
            ['key' => 'logbooks', 'label' => 'Logbooks'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listVerificationAlerts(): array
    {
        if (! self::tablesReady() || ! Schema::hasColumn('dcs_distribution_offices', 'verification_required')) {
            return [];
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $accDetailsTbl = Schema::hasTable('sys_account_details') ? 'sys_account_details' : 'account_details';

        $rows = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'dist.request_id')
            ->join($officeTbl . ' as o', 'o.id', '=', 'doff.office_id')
            ->leftJoin($accDetailsTbl . ' as ad', 'ad.account_id', '=', 'doff.client_acknowledged_by')
            ->where('doff.verification_required', true)
            ->where(function ($q) {
                RegisterQueryHelper::applyNotDeleted($q, 'dr');
                RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
            })
            ->orderByDesc('doff.client_acknowledged_at')
            ->orderByDesc('doff.id')
            ->get([
                'doff.id',
                'doff.copy_no',
                'doff.copies',
                'dist.request_id',
                'ml.doc_title',
                'ml.doc_no',
                'ml.revise_no',
                'o.office_name',
                'ad.first_name',
                'ad.last_name',
                'doff.client_acknowledged_at',
            ]);

        $alerts = [];
        foreach ($rows as $row) {
            $staff = trim(trim((string) ($row->first_name ?? '')) . ' ' . trim((string) ($row->last_name ?? '')));
            if ($staff === '') {
                $staff = 'A staff member';
            }
            $title = trim((string) ($row->doc_title ?? ''));
            if ($title === '') {
                $title = trim((string) ($row->doc_no ?? 'controlled document'));
            }
            $rev = (int) ($row->revise_no ?? 0);
            $copy = self::copyLabel((int) ($row->copy_no ?? 0), (int) ($row->copies ?? 1));
            $alerts[] = [
                'id' => (int) $row->id,
                'request_id' => (int) $row->request_id,
                'message' => 'Verification Required: ' . $staff
                    . ' (' . trim((string) ($row->office_name ?? 'office')) . ') acknowledged receipt of '
                    . $title . ' (Rev. ' . $rev . '), ' . $copy
                    . '. Please inspect the printed D&R List Form for their wet signature before confirming distribution.',
            ];
        }

        return $alerts;
    }

    /**
     * @param  array<string, int|string>  $revisionByDocKey  doc_no|request key => revise_no
     * @return list<array<string, mixed>>
     */
    public static function listAdminCards(
        ?string $search = null,
        ?string $docType = null,
        array $revisionByDocKey = []
    ): array {
        if (! self::tablesReady()) {
            return [];
        }

        // Cards load only after a document-type filter is chosen.
        $docType = trim((string) $docType);
        if ($docType === '') {
            return [];
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $search = trim((string) $search);

        $base = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'dist.request_id')
            ->join($officeTbl . ' as o', 'o.id', '=', 'doff.office_id')
            ->where(function ($q) {
                RegisterQueryHelper::applyNotDeleted($q, 'dr');
                RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
            });

        if ($docType !== 'all') {
            $parentId = RegisterQueryHelper::parentTypeIdMap()[$docType] ?? null;
            if ($parentId) {
                $base->where(function ($q) use ($parentId) {
                    $q->where('dr.doc_type_id', (int) $parentId)
                        ->orWhereExists(function ($r) use ($parentId) {
                            $r->select(DB::raw(1))
                                ->from('dcs_doc_types as st')
                                ->whereColumn('st.id', 'dr.sub_type_id')
                                ->where('st.parent_id', (int) $parentId);
                        })
                        ->orWhereExists(function ($r) use ($parentId) {
                            $r->select(DB::raw(1))
                                ->from('dcs_doc_types as mt')
                                ->whereColumn('mt.id', 'ml.doc_type_id')
                                ->where(function ($t) use ($parentId) {
                                    $t->where('mt.id', (int) $parentId)
                                        ->orWhere('mt.parent_id', (int) $parentId);
                                });
                        });
                });
            }
        }

        if ($search !== '') {
            $like = '%' . mb_strtolower($search) . '%';
            $base->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(COALESCE(ml.doc_title, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(ml.doc_no, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(o.office_name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(o.office_code, \'\')) LIKE ?', [$like]);
            });
        }

        $allRows = (clone $base)
            ->orderByDesc('ml.revise_no')
            ->orderBy('ml.doc_title')
            ->orderBy('ml.doc_no')
            ->when(
                Schema::hasColumn('dcs_distribution_offices', 'sort_order'),
                fn ($q) => $q->orderBy('doff.sort_order')
            )
            ->orderBy('o.office_name')
            ->get([
                'doff.id',
                'doff.office_id',
                'doff.copies',
                'doff.copy_no',
                'doff.distribution_status',
                'doff.copy_retrieval_status',
                'doff.old_version_label',
                'doff.verification_required',
                'doff.physical_signature_verified',
                'doff.client_acknowledged_at',
                'dist.request_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'o.office_name',
                'o.office_code',
            ]);

        if ($allRows->isEmpty()) {
            return [];
        }

        $isSearching = $search !== '';

        // Revision options per document family.
        $revisionsByKey = [];
        if ($isSearching) {
            $matchedDocNos = $allRows->map(fn ($r) => trim((string) ($r->doc_no ?? '')))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $matchedReqFallback = $allRows->map(fn ($r) => (int) $r->request_id)->unique()->all();

            $familyRows = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_distribution as dist', 'dist.request_id', '=', 'ml.request_id')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->where(function ($q) {
                    RegisterQueryHelper::applyNotDeleted($q, 'dr');
                    RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
                })
                ->where(function ($q) use ($matchedDocNos, $matchedReqFallback) {
                    if ($matchedDocNos !== []) {
                        $q->whereIn('ml.doc_no', $matchedDocNos);
                    }
                    $q->orWhereIn('ml.request_id', $matchedReqFallback);
                })
                ->get(['ml.request_id', 'ml.doc_no', 'ml.revise_no']);

            foreach ($familyRows as $row) {
                $docNo = trim((string) ($row->doc_no ?? ''));
                $family = $docNo !== '' ? mb_strtolower($docNo) : 'req:' . (int) $row->request_id;
                $revisionsByKey[$family][(int) ($row->revise_no ?? 0)] = (int) $row->request_id;
            }
        } else {
            foreach ($allRows as $row) {
                $docNo = trim((string) ($row->doc_no ?? ''));
                $family = $docNo !== '' ? mb_strtolower($docNo) : 'req:' . (int) $row->request_id;
                $revisionsByKey[$family][(int) ($row->revise_no ?? 0)] = (int) $row->request_id;
            }
        }

        $selectedRequestIds = [];

        if ($isSearching) {
            foreach ($allRows as $row) {
                $rid = (int) $row->request_id;
                $docNo = trim((string) ($row->doc_no ?? ''));
                $family = $docNo !== '' ? mb_strtolower($docNo) : 'req:' . $rid;
                $revMap = $revisionsByKey[$family] ?? [(int) ($row->revise_no ?? 0) => $rid];
                krsort($revMap, SORT_NUMERIC);
                $selectedRequestIds[$rid] = [
                    'family' => $family,
                    'revisions' => array_keys($revMap),
                    'selected_rev' => (int) ($row->revise_no ?? 0),
                ];
            }
        } else {
            foreach ($revisionsByKey as $family => $revMap) {
                krsort($revMap, SORT_NUMERIC);
                $latestRev = (int) array_key_first($revMap);
                $wanted = array_key_exists($family, $revisionByDocKey)
                    ? (int) $revisionByDocKey[$family]
                    : $latestRev;
                if (! isset($revMap[$wanted])) {
                    $wanted = $latestRev;
                }
                $selectedRequestIds[(int) $revMap[$wanted]] = [
                    'family' => $family,
                    'revisions' => array_keys($revMap),
                    'selected_rev' => $wanted,
                ];
            }
        }

        // Reload office rows for the selected request IDs (needed when revision changes without search).
        $requestIds = array_keys($selectedRequestIds);
        if ($requestIds === []) {
            return [];
        }

        $rows = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->join($officeTbl . ' as o', 'o.id', '=', 'doff.office_id')
            ->whereIn('dist.request_id', $requestIds)
            ->when(
                Schema::hasColumn('dcs_distribution_offices', 'sort_order'),
                fn ($q) => $q->orderBy('doff.sort_order')
            )
            ->orderBy('o.office_name')
            ->get([
                'doff.id',
                'doff.office_id',
                'doff.copies',
                'doff.copy_no',
                'doff.distribution_status',
                'doff.copy_retrieval_status',
                'doff.old_version_label',
                'doff.verification_required',
                'doff.physical_signature_verified',
                'doff.client_acknowledged_at',
                'dist.request_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'o.office_name',
                'o.office_code',
            ]);

        $cards = [];
        foreach ($rows as $row) {
            $key = (int) $row->request_id;
            if (! isset($selectedRequestIds[$key])) {
                continue;
            }
            if (! isset($cards[$key])) {
                $meta = $selectedRequestIds[$key];
                $cards[$key] = [
                    'request_id' => $key,
                    'doc_key' => $meta['family'],
                    'doc_no' => trim((string) ($row->doc_no ?? '')),
                    'doc_title' => trim((string) ($row->doc_title ?? '')) ?: 'Untitled document',
                    'rev_no' => (int) ($row->revise_no ?? 0),
                    'available_revisions' => array_values(array_map('intval', $meta['revisions'])),
                    'selected_rev' => (int) $meta['selected_rev'],
                    'total_copies' => 0,
                    'office_count' => 0,
                    'distributed_copies' => 0,
                    'pending_retrieval' => 0,
                    'offices' => [],
                ];
            }
            $copies = max(1, (int) ($row->copies ?? 1));
            $distributed = ($row->distribution_status ?? '') === self::DIST_DISTRIBUTED;
            $cards[$key]['total_copies'] += $copies;
            $cards[$key]['office_count']++;
            if ($distributed) {
                $cards[$key]['distributed_copies'] += $copies;
            }
            if (($row->copy_retrieval_status ?? '') === self::RET_PENDING) {
                $cards[$key]['pending_retrieval']++;
            }
            $cards[$key]['offices'][] = [
                'id' => (int) $row->id,
                'office_name' => trim((string) ($row->office_name ?? 'Office')),
                'office_code' => trim((string) ($row->office_code ?? '')),
                'copies' => $copies,
                'copy_no' => (int) ($row->copy_no ?? 0),
                'copy_label' => self::copyLabel((int) ($row->copy_no ?? 0), $copies),
                'distribution_status' => $row->distribution_status ?: self::DIST_PENDING,
                'copy_retrieval_status' => $row->copy_retrieval_status ?: self::RET_NA,
                'old_version_label' => trim((string) ($row->old_version_label ?? '')),
                'verification_required' => (bool) $row->verification_required,
                'physical_signature_verified' => (bool) $row->physical_signature_verified,
                'client_acknowledged' => ! empty($row->client_acknowledged_at),
            ];
        }

        return array_values($cards);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function officeRowPayload(int $officeRowId): ?array
    {
        if (! self::tablesReady() || $officeRowId < 1) {
            return null;
        }

        $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
        $row = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->join($officeTbl . ' as o', 'o.id', '=', 'doff.office_id')
            ->where('doff.id', $officeRowId)
            ->first([
                'doff.id',
                'doff.office_id',
                'doff.copies',
                'doff.copy_no',
                'doff.distribution_status',
                'doff.copy_retrieval_status',
                'doff.old_version_label',
                'doff.physical_signature_verified',
                'doff.verification_required',
                'doff.client_acknowledged_at',
                'dist.request_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'o.office_name',
                'o.office_code',
            ]);

        if (! $row) {
            return null;
        }

        $copies = max(1, (int) ($row->copies ?? 1));
        $title = trim((string) ($row->doc_title ?? '')) ?: '—';
        $rev = (int) ($row->revise_no ?? 0);

        return [
            'id' => (int) $row->id,
            'request_id' => (int) $row->request_id,
            'office_id' => (int) $row->office_id,
            'office_code' => trim((string) ($row->office_code ?? '')),
            'doc_no' => trim((string) ($row->doc_no ?? '')) ?: '—',
            'doc_title' => $title,
            'rev_no' => $rev,
            'doc_title_with_rev' => $title . ' (Rev. ' . $rev . ')',
            'recipient' => trim((string) ($row->office_name ?? '—')),
            'copies' => $copies,
            'copy_label' => self::copyLabel((int) ($row->copy_no ?? 0), $copies),
            'distribution_status' => $row->distribution_status ?: self::DIST_PENDING,
            'copy_retrieval_status' => $row->copy_retrieval_status ?: self::RET_NA,
            'physical_signature_verified' => (bool) $row->physical_signature_verified,
            'verification_required' => (bool) $row->verification_required,
            'client_acknowledged' => ! empty($row->client_acknowledged_at),
            'old_version_label' => trim((string) ($row->old_version_label ?? '')),
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function handoverUpdate(
        int $officeRowId,
        bool $signatureVerified,
        string $distributionStatus,
        string $retrievalStatus
    ): array {
        RegisterQueryHelper::assertFullDcsUser('reports');
        if (! self::tablesReady()) {
            return ['ok' => false, 'message' => 'Distribution tracking is not available yet.'];
        }

        $distributionStatus = $distributionStatus === self::DIST_DISTRIBUTED
            ? self::DIST_DISTRIBUTED
            : self::DIST_PENDING;
        $retrievalStatus = match ($retrievalStatus) {
            self::RET_PENDING, self::RET_RETRIEVED, self::RET_NA => $retrievalStatus,
            default => self::RET_NA,
        };

        if ($distributionStatus === self::DIST_DISTRIBUTED && ! $signatureVerified) {
            return [
                'ok' => false,
                'message' => 'Confirm the physical wet signature on QMS-FM-082 before marking this office as Distributed.',
            ];
        }

        $row = DB::table('dcs_distribution_offices')->where('id', $officeRowId)->first();
        if (! $row) {
            return ['ok' => false, 'message' => 'That office tracking row was not found.'];
        }

        $wasAwaitingVerify = ! empty($row->verification_required) || ! empty($row->client_acknowledged_at);
        $userId = (int) (auth()->id() ?? 0);
        $update = [
            'distribution_status' => $distributionStatus,
            'copy_retrieval_status' => $retrievalStatus,
        ];

        if ($distributionStatus === self::DIST_DISTRIBUTED) {
            $update['physical_signature_verified'] = true;
            $update['physical_signature_verified_at'] = now();
            $update['physical_signature_verified_by'] = $userId > 0 ? $userId : null;
            $update['verification_required'] = false;
            if (Schema::hasColumn('dcs_distribution_offices', 'office_received_at') && empty($row->office_received_at)) {
                $update['office_received_at'] = now();
                $update['office_received_by'] = $userId > 0 ? $userId : null;
            }
        } else {
            $update['physical_signature_verified'] = false;
            $update['physical_signature_verified_at'] = null;
            $update['physical_signature_verified_by'] = null;
            if (Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
                $update['office_received_at'] = null;
                $update['office_received_by'] = null;
            }
            $update['verification_required'] = ! empty($row->client_acknowledged_at);
        }

        DB::table('dcs_distribution_offices')->where('id', $officeRowId)->update($update);

        if ($distributionStatus === self::DIST_DISTRIBUTED && $wasAwaitingVerify) {
            self::notifyClientAcknowledgementVerified($officeRowId);
        }

        RegisterPersistHelper::logAdminChange(
            'Updated D&R handover for tracking #' . $officeRowId
            . ' — ' . self::distributionShort($distributionStatus)
            . ' / ' . self::retrievalShort($retrievalStatus)
        );

        return ['ok' => true, 'message' => 'Handover status saved.'];
    }

    /**
     * Admin rejects client accept/acknowledge — document still at Records Office, no wet signature.
     *
     * @return array{ok: bool, message: string}
     */
    public static function rejectClientAcknowledgement(int $officeRowId): array
    {
        RegisterQueryHelper::assertFullDcsUser('reports');
        if (! self::tablesReady()) {
            return ['ok' => false, 'message' => 'Distribution tracking is not available yet.'];
        }

        $payload = self::officeRowPayload($officeRowId);
        if (! $payload) {
            return ['ok' => false, 'message' => 'That office tracking row was not found.'];
        }

        $clear = [
            'verification_required' => false,
            'client_acknowledged_at' => null,
            'client_acknowledged_by' => null,
            'distribution_status' => self::DIST_PENDING,
            'physical_signature_verified' => false,
            'physical_signature_verified_at' => null,
            'physical_signature_verified_by' => null,
        ];
        if (Schema::hasColumn('dcs_distribution_offices', 'office_received_at')) {
            $clear['office_received_at'] = null;
            $clear['office_received_by'] = null;
        }
        DB::table('dcs_distribution_offices')->where('id', $officeRowId)->update($clear);

        $adminName = RegisterQueryHelper::currentUserDisplayName();
        $officeCode = $payload['office_code'] !== ''
            ? $payload['office_code']
            : null;
        if (! $officeCode) {
            $officeTbl = Schema::hasTable('sys_office') ? 'sys_office' : 'office';
            $officeCode = (string) DB::table($officeTbl)->where('id', $payload['office_id'])->value('office_code');
        }

        if ($officeCode) {
            DcsNotificationService::notifyClientAcknowledgementRejected(
                $officeCode,
                $adminName,
                $payload['doc_title_with_rev']
            );
        }

        RegisterPersistHelper::logAdminChange(
            'Rejected client D&R acknowledgement for tracking #' . $officeRowId
            . ' — ' . $payload['doc_title_with_rev']
        );

        return [
            'ok' => true,
            'message' => 'Notification sent. Distribution remains Pending Pickup.',
        ];
    }

    protected static function notifyClientAcknowledgementVerified(int $officeRowId): void
    {
        $payload = self::officeRowPayload($officeRowId);
        if (! $payload || $payload['office_code'] === '') {
            return;
        }

        DcsNotificationService::notifyClientAcknowledgementVerified(
            $payload['office_code'],
            RegisterQueryHelper::currentUserDisplayName(),
            $payload['doc_title_with_rev']
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function confirmDistribution(int $officeRowId): array
    {
        return self::handoverUpdate(
            $officeRowId,
            true,
            self::DIST_DISTRIBUTED,
            (string) (DB::table('dcs_distribution_offices')->where('id', $officeRowId)->value('copy_retrieval_status') ?: self::RET_NA)
        );
    }

    /**
     * Path B — client acknowledge does not mark Distributed.
     *
     * @return array{ok: bool, already?: bool, message: string}
     */
    public static function acknowledgeReceipt(int $requestId): array
    {
        OfficeIntakeHelper::assertCanAccessIntake();

        $officeId = RegisterQueryHelper::currentOfficeId();
        if (! $officeId || $requestId <= 0) {
            return ['ok' => false, 'message' => 'Your office is not assigned to this document.'];
        }
        if (! self::tablesReady()) {
            return ['ok' => false, 'message' => 'Receipt acknowledgement is not available yet.'];
        }

        $row = DB::table('dcs_document_distribution as dist')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->where('dist.request_id', $requestId)
            ->where('doff.office_id', $officeId)
            ->select('doff.*')
            ->first();

        if (! $row) {
            return ['ok' => false, 'message' => 'This document is not listed for your office.'];
        }

        if (($row->distribution_status ?? '') === self::DIST_DISTRIBUTED || ! empty($row->office_received_at)) {
            return [
                'ok' => true,
                'already' => true,
                'message' => 'Records Office already confirmed distribution of this copy.',
            ];
        }

        if (! empty($row->verification_required) || ! empty($row->client_acknowledged_at)) {
            return [
                'ok' => true,
                'already' => true,
                'message' => 'Receipt already acknowledged. Records Personnel still needs to verify the wet signature on the printed D&R list.',
            ];
        }

        $userId = (int) (auth()->id() ?? 0);
        DB::table('dcs_distribution_offices')->where('id', $row->id)->update([
            'client_acknowledged_at' => now(),
            'client_acknowledged_by' => $userId > 0 ? $userId : null,
            'verification_required' => true,
        ]);

        $ml = Schema::hasTable('dcs_masterlist_registration')
            ? DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first(['doc_title', 'doc_no', 'revise_no'])
            : null;
        $title = trim((string) ($ml->doc_title ?? ''));
        $docNo = trim((string) ($ml->doc_no ?? ''));
        $rev = (int) ($ml->revise_no ?? 0);
        $label = $title !== '' ? $title : ($docNo !== '' ? $docNo : 'controlled document');
        $labelWithRev = $label . ' (Rev. ' . $rev . ')';
        $copy = self::copyLabel((int) ($row->copy_no ?? 0), (int) ($row->copies ?? 1));
        $staff = RegisterQueryHelper::currentUserDisplayName();
        $officeName = RegisterQueryHelper::currentOfficeName();

        DcsNotificationService::notifyAdminClientAcknowledged(
            $staff,
            $officeName,
            $labelWithRev,
            $copy,
            $requestId,
            (int) $row->id
        );

        RegisterPersistHelper::logAdminChange(
            'Client acknowledged D&R receipt for request #' . $requestId . ' — ' . $labelWithRev . ' (' . $copy . ')'
        );

        return [
            'ok' => true,
            'already' => false,
            'message' => 'Records Office has been notified. Distribution stays pending until they confirm the wet signature on the printed D&R list.',
        ];
    }

    /**
     * Previous revisions distributed to this office (for "Show Old Version Status").
     *
     * @return list<array{doc_title: string, rev_no: int, total_copies: int, status: string, status_label: string}>
     */
    public static function listOldVersionsForOffice(int $requestId, ?int $officeId = null): array
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        if (! $officeId || $requestId < 1 || ! self::tablesReady()) {
            return [];
        }

        $current = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first([
            'doc_no', 'revise_no', 'doc_title',
        ]);
        if (! $current) {
            return [];
        }

        $docNo = trim((string) ($current->doc_no ?? ''));
        $currentRev = (int) ($current->revise_no ?? 0);
        if ($docNo === '') {
            return [];
        }

        // Latest revision's retrieval status for this office drives Pending Return / Returned.
        $latestDist = DB::table('dcs_document_distribution as dist')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->where('doff.office_id', $officeId)
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)])
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->first(['doff.copy_retrieval_status', 'ml.revise_no']);

        $latestRetrieval = $latestDist->copy_retrieval_status ?? self::RET_NA;

        $older = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_distribution as dist', 'dist.request_id', '=', 'ml.request_id')
            ->join('dcs_distribution_offices as doff', 'doff.distribution_id', '=', 'dist.id')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('doff.office_id', $officeId)
            ->whereRaw('LOWER(TRIM(ml.doc_no)) = ?', [mb_strtolower($docNo)])
            ->where('ml.request_id', '!=', $requestId)
            ->where('ml.revise_no', '<', $currentRev)
            ->where(function ($q) {
                RegisterQueryHelper::applyNotDeleted($q, 'dr');
                RegisterQueryHelper::applyExcludeDrafts($q, 'dr');
            })
            ->orderByDesc('ml.revise_no')
            ->get([
                'ml.doc_title',
                'ml.revise_no',
                'doff.copies',
                'doff.copy_retrieval_status',
                'ml.request_id',
            ]);

        // Deduplicate by revise_no (keep highest).
        $seen = [];
        $out = [];
        foreach ($older as $row) {
            $rev = (int) ($row->revise_no ?? 0);
            if (isset($seen[$rev])) {
                continue;
            }
            $seen[$rev] = true;

            // Prefer the newer revision's retrieval flag for overall possession status.
            $status = $latestRetrieval === self::RET_RETRIEVED
                ? 'returned'
                : 'pending_return';

            // If this older row itself was marked retrieved on a later chain, treat as returned.
            if (($row->copy_retrieval_status ?? '') === self::RET_RETRIEVED) {
                $status = 'returned';
            }

            $out[] = [
                'doc_title' => trim((string) ($row->doc_title ?? '')) ?: trim((string) ($current->doc_title ?? 'Document')),
                'rev_no' => $rev,
                'total_copies' => max(1, (int) ($row->copies ?? 1)),
                'status' => $status,
                'status_label' => $status === 'returned' ? 'Returned' : 'Pending Return',
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listClientNotices(?int $officeId = null): array
    {
        $officeId = $officeId ?? RegisterQueryHelper::currentOfficeId();
        if (! $officeId || ! self::tablesReady()) {
            return [];
        }

        $rows = DB::table('dcs_distribution_offices as doff')
            ->join('dcs_document_distribution as dist', 'dist.id', '=', 'doff.distribution_id')
            ->join('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dist.request_id')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'dist.request_id')
            ->where('doff.office_id', $officeId)
            ->where(function ($q) {
                $q->where('doff.distribution_status', self::DIST_PENDING)
                    ->orWhere('doff.copy_retrieval_status', self::RET_PENDING)
                    ->orWhere('doff.verification_required', true);
            });

        RegisterQueryHelper::applyNotDeleted($rows, 'dr');
        RegisterQueryHelper::applyExcludeDrafts($rows, 'dr');
        RegisterQueryHelper::applyLatestRevisionStatus($rows, 'ml');

        $rows = $rows
            ->orderBy('ml.doc_title')
            ->get([
                'dist.request_id',
                'ml.doc_title',
                'ml.doc_no',
                'ml.revise_no',
                'doff.copy_no',
                'doff.copies',
                'doff.distribution_status',
                'doff.copy_retrieval_status',
                'doff.old_version_label',
                'doff.verification_required',
            ]);

        $notices = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row->doc_title ?? ''));
            if ($title === '') {
                $title = trim((string) ($row->doc_no ?? 'controlled document'));
            }
            $copy = self::copyLabel((int) ($row->copy_no ?? 0), (int) ($row->copies ?? 1));
            $pendingPickup = ($row->distribution_status ?? '') !== self::DIST_DISTRIBUTED;
            $pendingRetrieval = ($row->copy_retrieval_status ?? '') === self::RET_PENDING;
            $awaitingAdmin = (bool) $row->verification_required && $pendingPickup;

            if ($awaitingAdmin) {
                $notices[] = [
                    'type' => 'verify',
                    'title' => $title,
                    'text' => 'You acknowledged ' . $title . ' (' . $copy . '). Records Personnel still needs to verify the wet signature on the printed D&R list.',
                ];
            } elseif ($pendingPickup) {
                $old = trim((string) ($row->old_version_label ?? ''));
                $isRev = $pendingRetrieval || (int) ($row->revise_no ?? 0) > 0;
                $notices[] = [
                    'type' => $isRev ? 'exchange' : 'pickup',
                    'title' => $title,
                    'text' => $isRev
                        ? 'A new revision of ' . $title . ' is ready for pickup (' . $copy . '). Bring your current physical copy'
                            . ($old !== '' ? ' (' . $old . ')' : '')
                            . ' to the Records Office to return it in exchange.'
                        : $title . ' is ready for pickup at the Records Office (' . $copy . '). Send a representative to sign the physical D&R list.',
                ];
            } elseif ($pendingRetrieval) {
                $old = trim((string) ($row->old_version_label ?? 'previous paper copy'));
                $notices[] = [
                    'type' => 'retrieval',
                    'title' => $title,
                    'text' => 'Audit warning: ' . $title . ' is distributed, but ' . $old . ' is still Pending Retrieval. Return the old paper copy to the Records Office for the Obsolete stamp.',
                ];
            }
        }

        return $notices;
    }

    public static function isPickupNotification(?string $redirectUrl, ?string $content = null): bool
    {
        $url = trim((string) $redirectUrl);
        if ($url !== '') {
            $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
            parse_str($query, $params);
            if (! empty($params['pickup'])) {
                return true;
            }
        }

        $content = (string) $content;

        return str_contains($content, 'ready for pickup')
            || str_contains($content, 'new controlled document to receive');
    }

    /**
     * @param  list<string>  $cols
     * @return list<string>
     */
    public static function trackingSelectColumns(): array
    {
        $cols = ['id', 'office_id', 'office_received_at', 'office_received_by'];
        foreach ([
            'copy_no',
            'copies',
            'distribution_status',
            'copy_retrieval_status',
            'old_version_label',
            'physical_signature_verified',
            'client_acknowledged_at',
            'client_acknowledged_by',
            'verification_required',
        ] as $col) {
            if (Schema::hasColumn('dcs_distribution_offices', $col)) {
                $cols[] = $col;
            }
        }

        return $cols;
    }
}
