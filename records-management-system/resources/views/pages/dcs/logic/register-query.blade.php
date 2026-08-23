<?php

namespace App\Helpers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Register/Update/Edit/History reads. PK is always `id`.
 */
class RegisterQueryHelper
{
    public static function pgBool(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }
        if (is_int($val) || is_float($val)) {
            return (bool) $val;
        }

        return in_array(strtolower(trim((string) $val)), ['1', 't', 'true', 'yes', 'on'], true);
    }

    public static function findDocumentRequest(int $id): ?object
    {
        $query = DB::table('dcs_document_requests')->where('id', $id);
        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->first();
    }

    public static function findTrashedDocumentRequest(int $id): ?object
    {
        if (!Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            return null;
        }

        return DB::table('dcs_document_requests')->where('id', $id)->whereNotNull('deleted_at')->first();
    }

    public static function supportsSoftDelete(): bool
    {
        return Schema::hasColumn('dcs_document_requests', 'deleted_at');
    }

    public static function isSyllabiLikeName(?string $name): bool
    {
        if ($name === null) {
            return false;
        }
        $n = mb_strtolower($name);

        return str_contains($n, 'syllab') || str_contains($n, 'tos') || str_contains($n, 'rubric');
    }

    /** Parent doc-type IDs keyed by report/dashboard tab. */
    public static function parentTypeIdMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $byName = DB::table('dcs_doc_types')
            ->whereNull('parent_id')
            ->get(['id', 'doc_type_name'])
            ->mapWithKeys(fn ($row) => [mb_strtolower(trim($row->doc_type_name)) => (int) $row->id]);

        $map = [
            'internal_docs' => $byName['internal'] ?? 1,
            'internal_forms' => $byName['internal forms'] ?? 2,
            'external_docs' => $byName['external'] ?? 3,
            'forms' => $byName['forms'] ?? 4,
            'logbooks' => $byName['logbooks'] ?? 5,
        ];

        return $map;
    }

    public static function requestIdsWithSameDocType(object $docRequest): array
    {
        $query = DB::table('dcs_document_requests')
            ->where('doc_type_id', $docRequest->doc_type_id);

        if ($docRequest->sub_type_id === null || $docRequest->sub_type_id === '') {
            $query->whereNull('sub_type_id');
        } else {
            $query->where('sub_type_id', $docRequest->sub_type_id);
        }

        return $query->pluck('id')->all();
    }

    public static function formatDate(mixed $val): string
    {
        if (!$val) {
            return '';
        }

        return Carbon::parse($val)->format('Y-m-d');
    }

    public static function formatTime(mixed $val): string
    {
        if (!$val) {
            return '';
        }

        return Carbon::parse($val)->format('H:i');
    }

    public static function parentDocTypes()
    {
        return DB::table('dcs_doc_types')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->get(['id', 'doc_type_name']);
    }

    public static function isLatestRevisionStatus(?string $status): bool
    {
        return strtolower(trim((string) $status)) !== 'obsolete';
    }

    /** Cast mixed ID lists (Postgres bigint strings) to unique ints. */
    public static function intIds($ids): array
    {
        return collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
    }

    /** Exclude soft-deleted document requests when the column exists. */
    public static function applyNotDeleted($query, string $alias = 'dr')
    {
        if (Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            $query->whereNull($alias . '.deleted_at');
        }

        return $query;
    }

    /** Search/stamp/preview: latest (including null/empty status), numbered obsolete, and empty-doc-no rows. */
    public static function visibleRequestIds(): array
    {
        $mlIds = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id');
        self::applyNotDeleted($mlIds, 'dr');
        $mlIds = $mlIds->pluck('ml.request_id');

        $noMlIds = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->whereNull('ml.id');
        self::applyNotDeleted($noMlIds, 'dr');
        $noMlIds = $noMlIds->pluck('dr.id');

        return self::intIds($mlIds->merge($noMlIds));
    }

    /** Update list: only latest (null/empty treated as latest) plus incomplete registrations. */
    public static function latestEditableRequestIds(): array
    {
        $latestIds = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where(function ($q) {
                $q->whereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '')
                    ->orWhere('ml.revision_status', 'latest');
            });
        self::applyNotDeleted($latestIds, 'dr');
        $latestIds = $latestIds->pluck('ml.request_id');

        $noMlIds = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->where(function ($q) {
                $q->whereNull('ml.id')
                    ->orWhereNull('ml.doc_no')
                    ->orWhere('ml.doc_no', '');
            });
        self::applyNotDeleted($noMlIds, 'dr');
        $noMlIds = $noMlIds->pluck('dr.id');

        return self::intIds($latestIds->merge($noMlIds));
    }

    /** Query params that still target a document when doc_no is blank. */
    public static function documentSearchParams(object $row): array
    {
        $docNo = trim((string) ($row->doc_no ?? ''));
        $title = trim((string) ($row->doc_title ?? ''));
        $requestId = (int) ($row->request_id ?? 0);
        $search = $docNo !== '' ? $docNo : ($title !== '' ? $title : (string) $requestId);
        $params = ['search' => $search];
        if ($docNo === '' && $requestId > 0) {
            $params['request_id'] = $requestId;
        }

        return $params;
    }

    public static function updateList(string $search, string $docTypeId, int $page, int $perPage = 15): array
    {
        $empty = [
            'rows' => [],
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
        ];

        $visibleIds = self::visibleRequestIds();
        if ($visibleIds === []) {
            return $empty;
        }

        $query = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_request_form as drf', 'drf.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_change_notice as dcn', 'dcn.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_retrieval as ret', 'ret.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_distribution as dist', 'dist.request_id', '=', 'dr.id')
            ->whereIn('dr.id', $visibleIds);
        self::applyNotDeleted($query, 'dr');
        $query->select([
            'dr.id',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name',
            'ml.id as ml_id',
            'ml.doc_no',
            'ml.doc_title as ml_title',
            'ml.revise_no',
            'ml.revision_status',
            'drf.doc_title as drf_title',
            'drf.id as drf_id',
            'dcn.id as dcn_id',
            'ret.id as ret_id',
            'dist.id as dist_id',
        ]);

        if ($docTypeId !== '' && $docTypeId !== 'all') {
            $query->where('dr.doc_type_id', (int) $docTypeId);
        }

        $search = trim($search);
        $matchedIds = null;
        if ($search !== '') {
            $like = '%' . $search . '%';
            $matchQuery = (clone $query)->where(function ($q) use ($like) {
                $q->whereRaw('dr.id::text ilike ?', [$like])
                    ->orWhere('ml.doc_no', 'ilike', $like)
                    ->orWhere('ml.doc_title', 'ilike', $like)
                    ->orWhere('drf.drf_no', 'ilike', $like)
                    ->orWhere('drf.doc_title', 'ilike', $like)
                    ->orWhere('dcn.dcn_no', 'ilike', $like);
            });
            $matchedIds = $matchQuery->pluck('dr.id')->map(fn ($id) => (int) $id)->all();
            if ($matchedIds === []) {
                return $empty;
            }
        }

        $documents = $query->orderByDesc('dr.id')->get();

        $mapRow = function ($doc): array {
            $docNo = trim((string) ($doc->doc_no ?? ''));
            $title = $doc->ml_title ?: ($doc->drf_title ?: 'N/A');
            $status = strtolower(trim((string) ($doc->revision_status ?? '')));
            if ($status === '') {
                $status = 'latest';
            }

            return [
                'request_id' => (int) $doc->id,
                'doc_type_id' => (int) ($doc->doc_type_id ?? 0),
                'sub_type_id' => $doc->sub_type_id ? (int) $doc->sub_type_id : 0,
                'doc_no' => $docNo !== '' ? $docNo : 'N/A',
                'title' => $title,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'doc_type' => $doc->doc_type_name ?? 'N/A',
                'revision_status' => $status,
                'is_latest' => $status !== 'obsolete',
                'edit_url' => route('dcs.register.edit', $doc->id),
                'history_url' => $docNo !== '' ? route('dcs.register.history', $docNo) : null,
                'can_delete' => $status !== 'obsolete',
            ];
        };

        $rows = $documents->map($mapRow);

        $grouped = $rows->groupBy(function ($row) {
            if ($row['doc_no'] === 'N/A') {
                return 'no_ml_' . $row['request_id'];
            }

            return strtolower($row['doc_no']) . '||' . $row['doc_type_id'] . '||' . $row['sub_type_id'];
        });

        $groups = collect();
        foreach ($grouped as $family) {
            $sorted = $family->sortByDesc('rev_no')->sortByDesc('request_id')->values();
            $parent = $family->first(fn ($r) => $r['is_latest']) ?? $sorted->first();
            $children = $family
                ->filter(fn ($r) => $r['request_id'] !== $parent['request_id'])
                ->sortByDesc('rev_no')
                ->values()
                ->all();

            $groups->push([
                'doc_no' => $parent['doc_no'],
                'parent' => $parent,
                'children' => $children,
                'has_revisions' => $children !== [],
                'revision_count' => $family->count(),
                'sort_id' => $parent['request_id'],
                'member_ids' => $family->pluck('request_id')->all(),
            ]);
        }

        // Stack renumbered prior doc numbers under the tip (same as Database).
        if (Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            $byKey = [];
            foreach ($groups as $g) {
                $p = $g['parent'];
                if (($p['doc_no'] ?? 'N/A') === 'N/A') {
                    continue;
                }
                $key = strtolower($p['doc_no']) . '||' . $p['doc_type_id'] . '||' . $p['sub_type_id'];
                $byKey[$key] = $g;
            }

            $fromMap = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->whereIn('ml.request_id', $visibleIds)
                ->whereNotNull('ml.revised_from_doc_no')
                ->where('ml.revised_from_doc_no', '!=', '')
                ->select('ml.doc_no', 'ml.revised_from_doc_no', 'dr.doc_type_id', 'dr.sub_type_id')
                ->get();

            $absorb = [];
            foreach ($fromMap as $link) {
                $tipNo = trim((string) $link->doc_no);
                $fromNo = trim((string) $link->revised_from_doc_no);
                if ($tipNo === '' || $fromNo === '' || strcasecmp($tipNo, $fromNo) === 0) {
                    continue;
                }
                $typeId = (int) $link->doc_type_id;
                $subId = $link->sub_type_id ? (int) $link->sub_type_id : 0;
                $tipKey = strtolower($tipNo) . '||' . $typeId . '||' . $subId;
                $fromKey = strtolower($fromNo) . '||' . $typeId . '||' . $subId;
                if (!isset($byKey[$tipKey], $byKey[$fromKey]) || $tipKey === $fromKey) {
                    continue;
                }
                $absorb[$fromKey] = $tipKey;
            }

            if ($absorb !== []) {
                $merged = collect();
                $consumed = [];
                foreach ($groups as $g) {
                    $p = $g['parent'];
                    if (($p['doc_no'] ?? 'N/A') === 'N/A') {
                        $merged->push($g);
                        continue;
                    }
                    $key = strtolower($p['doc_no']) . '||' . $p['doc_type_id'] . '||' . $p['sub_type_id'];
                    if (isset($absorb[$key])) {
                        $consumed[$key] = true;
                        continue;
                    }
                    $children = $g['children'];
                    $memberIds = $g['member_ids'];
                    // Pull in absorbed prior families as obsolete children.
                    foreach ($absorb as $fromKey => $tipKey) {
                        if ($tipKey !== $key || !isset($byKey[$fromKey])) {
                            continue;
                        }
                        $prior = $byKey[$fromKey];
                        $priorParent = $prior['parent'];
                        $priorParent['is_latest'] = false;
                        $priorParent['revision_status'] = 'obsolete';
                        $priorParent['can_delete'] = false;
                        $children[] = $priorParent;
                        foreach ($prior['children'] as $c) {
                            $children[] = $c;
                        }
                        $memberIds = array_merge($memberIds, $prior['member_ids']);
                        $consumed[$fromKey] = true;
                    }
                    usort($children, fn ($a, $b) => ($b['rev_no'] <=> $a['rev_no']) ?: ($b['request_id'] <=> $a['request_id']));
                    $g['children'] = $children;
                    $g['has_revisions'] = $children !== [];
                    $g['revision_count'] = 1 + count($children);
                    $g['member_ids'] = array_values(array_unique($memberIds));
                    $merged->push($g);
                }
                $groups = $merged;
            }
        }

        if ($matchedIds !== null) {
            $matchSet = array_flip($matchedIds);
            $groups = $groups->filter(function ($g) use ($matchSet) {
                foreach ($g['member_ids'] as $id) {
                    if (isset($matchSet[$id])) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        $groups = $groups->sortByDesc('sort_id')->values();

        $total = $groups->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $pageGroups = $groups->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'rows' => $pageGroups,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
        ];
    }

    public static function recycleBinList(string $search, int $page, int $perPage = 15): array
    {
        if (!Schema::hasColumn('dcs_document_requests', 'deleted_at')) {
            return [
                'rows' => [],
                'total' => 0,
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $perPage,
            ];
        }

        $query = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_masterlist_registration as ml', 'ml.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_request_form as drf', 'drf.request_id', '=', 'dr.id')
            ->whereNotNull('dr.deleted_at')
            ->orderByDesc('dr.deleted_at');

        $select = [
            'dr.id',
            'dr.deleted_at',
            'dt.doc_type_name',
            'ml.doc_no',
            'ml.doc_title as ml_title',
            'ml.revise_no',
            'drf.doc_title as drf_title',
        ];
        if (Schema::hasColumn('dcs_document_requests', 'deleted_by')) {
            $select[] = 'dr.deleted_by';
        }

        $query->select($select);

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('dr.id::text ilike ?', [$like])
                    ->orWhere('ml.doc_no', 'ilike', $like)
                    ->orWhere('ml.doc_title', 'ilike', $like)
                    ->orWhere('drf.doc_title', 'ilike', $like);
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $documents = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $deletedByIds = $documents->pluck('deleted_by')->filter()->unique()->all();
        $deletedByNames = $deletedByIds && Schema::hasColumn('dcs_document_requests', 'deleted_by')
            ? DB::table('account_details')
                ->whereIn('account_id', $deletedByIds)
                ->get()
                ->mapWithKeys(fn ($d) => [(int) $d->account_id => trim($d->first_name . ' ' . $d->last_name)])
            : collect();

        $rows = $documents->map(function ($doc) use ($deletedByNames) {
            $title = $doc->ml_title ?: ($doc->drf_title ?: 'N/A');
            $deletedBy = isset($doc->deleted_by) && $doc->deleted_by
                ? ($deletedByNames[(int) $doc->deleted_by] ?? null)
                : null;

            return [
                'request_id' => $doc->id,
                'doc_no' => $doc->doc_no ?: 'N/A',
                'title' => $title,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'doc_type' => $doc->doc_type_name ?? 'N/A',
                'deleted_at' => $doc->deleted_at
                    ? \Carbon\Carbon::parse($doc->deleted_at)->format('M d, Y h:i A')
                    : '—',
                'deleted_by' => $deletedBy,
            ];
        })->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
        ];
    }

    public static function reviewList(string $search, string $docTypeId, int $page, int $perPage = 15): array
    {
        $empty = [
            'rows' => [],
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'filtered' => trim($search) !== '' || ($docTypeId !== '' && $docTypeId !== 'all'),
        ];

        $visibleIds = self::visibleRequestIds();
        if ($visibleIds === []) {
            return $empty;
        }

        // Prefer rows marked latest; fall back to highest revise_no / id per doc_no family.
        $latestMl = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereNotNull('ml.doc_no')
            ->where('ml.doc_no', '!=', '');
        self::applyNotDeleted($latestMl, 'dr');
        $latestMl = $latestMl
            ->where(function ($q) {
                $q->whereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '')
                    ->orWhere('ml.revision_status', 'latest');
            })
            ->select('ml.doc_no', DB::raw('MAX(ml.id) as ml_id'))
            ->groupBy('ml.doc_no');

        $revCounts = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->whereNotNull('ml.doc_no')
            ->where('ml.doc_no', '!=', '');
        self::applyNotDeleted($revCounts, 'dr');
        $revCounts = $revCounts
            ->select('ml.doc_no', DB::raw('COUNT(*) as rev_count'))
            ->groupBy('ml.doc_no');

        $query = DB::table('dcs_masterlist_registration as ml')
            ->joinSub($latestMl, 'latest', function ($join) {
                $join->on('ml.id', '=', 'latest.ml_id');
            })
            ->joinSub($revCounts, 'rc', function ($join) {
                $join->on('rc.doc_no', '=', 'ml.doc_no');
            })
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_document_request_form as drf', 'drf.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_change_notice as dcn', 'dcn.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_retrieval as ret', 'ret.request_id', '=', 'dr.id')
            ->leftJoin('dcs_document_distribution as dist', 'dist.request_id', '=', 'dr.id')
            ->whereIn('dr.id', $visibleIds);
        self::applyNotDeleted($query, 'dr');
        $query->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select([
                'dr.id as request_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'dr.doc_type_id',
                'dr.sub_type_id',
                'dt.doc_type_name',
                'rc.rev_count',
                'drf.id as drf_id',
                'dcn.id as dcn_id',
                'ml.id as ml_id',
                'ret.id as ret_id',
                'dist.id as dist_id',
            ]);

        if ($docTypeId !== '' && $docTypeId !== 'all') {
            $query->where('dr.doc_type_id', (int) $docTypeId);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('ml.doc_no', 'ilike', $like)
                    ->orWhere('ml.doc_title', 'ilike', $like);
            });
        }

        // Resolve renumber lineage tips so prior numbers don't appear as separate review rows.
        $candidates = $query->limit(120)->get();
        $hasKeywords = Schema::hasColumn('dcs_masterlist_registration', 'keywords');
        $tipRows = collect();
        $seenTipKeys = [];
        foreach ($candidates as $hit) {
            $tip = self::resolveLineageTipMasterlist($hit, $visibleIds);
            if (!$tip) {
                continue;
            }
            $key = strtolower(trim((string) ($tip->doc_no ?? ''))) . '|'
                . (int) ($tip->doc_type_id ?? 0) . '|'
                . (int) ($tip->sub_type_id ?? 0);
            if (isset($seenTipKeys[$key])) {
                continue;
            }
            $seenTipKeys[$key] = true;
            $enriched = self::loadSearchDocumentRow((int) $tip->id, $hasKeywords);
            if ($enriched) {
                $enriched->rev_count = (int) ($hit->rev_count ?? 0);
                $enriched->drf_id = $hit->drf_id ?? null;
                $enriched->dcn_id = $hit->dcn_id ?? null;
                $enriched->ret_id = $hit->ret_id ?? null;
                $enriched->dist_id = $hit->dist_id ?? null;
                $tipRows->push($enriched);
            }
        }

        // Recount revisions across the full renumber chain for each tip.
        $tipRows = $tipRows->map(function ($doc) use ($visibleIds) {
            $docTypeId = (int) ($doc->doc_type_id ?? 0);
            $subTypeId = !empty($doc->sub_type_id) ? (int) $doc->sub_type_id : null;
            $chain = self::buildRenumberChain((string) $doc->doc_no, $docTypeId, $subTypeId, $visibleIds);
            $count = 0;
            foreach ($chain as $chainDocNo) {
                $count += count(self::masterlistRevisionsForDocNo($chainDocNo, $docTypeId, $subTypeId, $visibleIds));
            }
            $doc->rev_count = max($count, (int) ($doc->rev_count ?? 0));

            return $doc;
        })->values();

        $total = $tipRows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $documents = $tipRows->slice(($page - 1) * $perPage, $perPage)->values();

        $requestIds = $documents->pluck('request_id')->all();
        $drfIds = $requestIds ? DB::table('dcs_document_request_form')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();
        $dcnIds = $requestIds ? DB::table('dcs_document_change_notice')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();
        $distIds = $requestIds ? DB::table('dcs_document_distribution')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();
        $retIds = $requestIds ? DB::table('dcs_document_retrieval')->whereIn('request_id', $requestIds)->pluck('request_id')->flip() : collect();

        $checklistNames = [
            1 => 'Document Request Form',
            2 => 'Document Change Notice',
            3 => 'Masterlist',
            4 => 'Retrieval',
            5 => 'Distribution',
        ];
        foreach (DB::table('dcs_checklist_types')->get(['id', 'checklist_name']) as $row) {
            $checklistNames[$row->id] = $row->checklist_name;
        }

        $rows = $documents->map(function ($doc) use ($checklistNames, $drfIds, $dcnIds, $distIds, $retIds) {
            $revCount = (int) ($doc->rev_count ?? 0);
            $canCompare = $revCount >= 2;
            $checklists = [];
            if (isset($drfIds[$doc->request_id])) {
                $checklists[] = $checklistNames[1] ?? 'DRF';
            }
            if (isset($dcnIds[$doc->request_id])) {
                $checklists[] = $checklistNames[2] ?? 'DCN';
            }
            $checklists[] = $checklistNames[3] ?? 'Masterlist';
            if (isset($retIds[$doc->request_id])) {
                $checklists[] = $checklistNames[4] ?? 'Retrieval';
            }
            if (isset($distIds[$doc->request_id])) {
                $checklists[] = $checklistNames[5] ?? 'Distribution';
            }

            return [
                'request_id' => (int) $doc->request_id,
                'doc_no' => (string) $doc->doc_no,
                'title' => $doc->doc_title ?: $doc->doc_no,
                'rev_no' => (int) ($doc->revise_no ?? 0),
                'rev_count' => $revCount,
                'can_compare' => $canCompare,
                'reason' => null,
                'doc_type' => $doc->type_name ?? 'N/A',
                'checklists' => $checklists,
            ];
        })->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'filtered' => $empty['filtered'],
        ];
    }

    public static function history(string $docNo): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            abort(404, 'Document not found.');
        }

        $anchor = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.doc_no', $docNo)
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select('ml.doc_no', 'ml.doc_title', 'dr.doc_type_id', 'dr.sub_type_id')
            ->first();

        if (!$anchor) {
            abort(404, 'Document not found.');
        }

        $visibleIds = self::visibleRequestIds();
        $docTypeId = (int) $anchor->doc_type_id;
        $subTypeId = $anchor->sub_type_id ? (int) $anchor->sub_type_id : null;
        $chain = self::buildRenumberChain($docNo, $docTypeId, $subTypeId, $visibleIds);

        $mls = collect();
        foreach ($chain as $chainDocNo) {
            $query = DB::table('dcs_masterlist_registration as ml')
                ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                ->whereIn('ml.request_id', $visibleIds)
                ->where('ml.doc_no', $chainDocNo)
                ->where('dr.doc_type_id', $docTypeId);

            if ($subTypeId) {
                $query->where('dr.sub_type_id', $subTypeId);
            } else {
                $query->whereNull('dr.sub_type_id');
            }

            self::applyNotDeleted($query, 'dr');

            foreach ($query
                ->orderByDesc('ml.revise_no')
                ->orderByDesc('ml.id')
                ->get([
                    'ml.*',
                    'dr.version_id as request_version_id',
                    'dr.approval_status',
                    'dr.created_at as request_created_at',
                    'dr.created_by as request_created_by',
                ]) as $row) {
                $mls->push($row);
            }
        }

        $mls = $mls
            ->unique(fn ($row) => (int) $row->request_id)
            ->sort(function ($a, $b) {
                $aTime = strtotime((string) ($a->request_created_at ?? $a->created_at ?? ''));
                $bTime = strtotime((string) ($b->request_created_at ?? $b->created_at ?? ''));
                if ($aTime !== $bTime) {
                    return $bTime <=> $aTime;
                }

                return ((int) ($b->id ?? 0)) <=> ((int) ($a->id ?? 0));
            })
            ->values();

        if ($mls->isEmpty()) {
            abort(404, 'Document not found.');
        }

        $requestIds = self::intIds($mls->pluck('request_id'));
        $docs = self::hydrateRequests(
            DB::table('dcs_document_requests')->whereIn('id', $requestIds)->get()
        )->keyBy(fn ($d) => (int) $d->id);

        $drfIds = $docs->map(fn ($d) => $d->documentRequestForm->id ?? null)->filter()->values()->all();
        $drfOffices = $drfIds
            ? DB::table('dcs_drf_offices as d')
                ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
                ->whereIn('d.document_request_form_id', $drfIds)
                ->get(['d.document_request_form_id', 'o.office_name'])
                ->groupBy('document_request_form_id')
            : collect();

        $dcnIds = $docs->map(fn ($d) => $d->documentChangeNotice->id ?? null)->filter()->values()->all();
        $dcnOffices = $dcnIds
            ? DB::table('dcs_dcn_offices as d')
                ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
                ->whereIn('d.dcn_id', $dcnIds)
                ->get(['d.dcn_id', 'o.office_name'])
                ->groupBy('dcn_id')
            : collect();

        foreach ($docs as $doc) {
            if ($doc->documentRequestForm) {
                $doc->documentRequestForm->offices = $drfOffices->get($doc->documentRequestForm->id, collect());
            }
            if ($doc->documentChangeNotice) {
                $doc->documentChangeNotice->offices = $dcnOffices->get($doc->documentChangeNotice->id, collect());
            }
        }

        $lookups = [
            'versions' => DB::table('dcs_version_type')->pluck('version_name', 'id'),
            'approvals' => DB::table('dcs_approval_body')->pluck('approval_name', 'id'),
            'colleges' => DB::table('dcs_colleges')->pluck('college_name', 'id'),
            'programs' => DB::table('dcs_programs')->pluck('program_name', 'id'),
            'semesters' => DB::table('dcs_semesters')->pluck('semester_name', 'id'),
            'years' => DB::table('dcs_school_years')->pluck('school_year', 'id'),
            'creators' => collect(),
        ];

        $creatorIds = $mls->pluck('request_created_by')->merge($mls->pluck('created_by'))->filter()->unique()->all();
        if ($creatorIds) {
            $lookups['creators'] = DB::table('account_details')
                ->whereIn('account_id', $creatorIds)
                ->get()
                ->mapWithKeys(fn ($d) => [(int) $d->account_id => trim($d->first_name . ' ' . $d->last_name)]);
        }

        $revisions = [];
        foreach ($mls as $i => $ml) {
            $doc = $docs->get((int) $ml->request_id);
            $revisions[] = self::buildHistoryRevision($ml, $doc, $lookups, $i === 0);
        }

        $lineageDocNos = $mls->pluck('doc_no')->filter()->unique()->values()->all();

        $noiseKeys = ['revise_no', 'registered_at', 'registered_by'];
        $summaryKeys = ['doc_type', 'sub_type', 'originator', 'effectivity', 'pages', 'purpose', 'deadline', 'drf_no', 'dcn_no'];

        for ($i = 0; $i < count($revisions); $i++) {
            $older = $revisions[$i + 1] ?? null;
            $changes = [];
            foreach ($revisions[$i]['sections'] as $s => $section) {
                $olderRows = [];
                if ($older) {
                    foreach ($older['sections'] as $os) {
                        if ($os['key'] === $section['key']) {
                            foreach ($os['rows'] as $or) {
                                $olderRows[$or['key']] = $or;
                            }
                        }
                    }
                }
                $sectionChanged = false;
                foreach ($section['rows'] as $r => $row) {
                    $prev = $olderRows[$row['key']] ?? null;
                    $isChanged = $older !== null
                        && ($prev === null || ($prev['compare'] ?? '') !== ($row['compare'] ?? ''));
                    $revisions[$i]['sections'][$s]['rows'][$r]['changed'] = $isChanged;
                    $revisions[$i]['sections'][$s]['rows'][$r]['previous'] = $isChanged
                        ? ($prev['value'] ?? '—')
                        : null;
                    if ($isChanged && !in_array($row['key'], $noiseKeys, true)) {
                        $changes[] = $revisions[$i]['sections'][$s]['rows'][$r];
                        $sectionChanged = true;
                    }
                }
                $revisions[$i]['sections'][$s]['changed'] = $sectionChanged;
                if (($section['key'] ?? '') === 'syllabi') {
                    $revisions[$i]['sections'][$s]['table'] = self::hstSyllabiTableFromRows(
                        $revisions[$i]['sections'][$s]['rows']
                    );
                }
            }
            $revisions[$i]['is_initial'] = $older === null;
            $revisions[$i]['changes'] = $changes;
            $revisions[$i]['changed_count'] = count($changes);
            $revisions[$i]['summary'] = self::hstPickRows($revisions[$i]['sections'], $summaryKeys);
        }

        return [
            'docNo' => $docNo,
            'docTitle' => $mls->first()->doc_title ?: $docNo,
            'lineageDocNos' => $lineageDocNos,
            'revisions' => $revisions,
        ];
    }

    public static function reviewCompare(string $docNo, ?int $leftId = null, ?int $rightId = null, ?string $extractTab = null): array
    {
        $empty = [
            'docNo' => trim($docNo),
            'docTitle' => '',
            'options' => [],
            'prior_options' => [],
            'left_id' => null,
            'right_id' => null,
            'latest_id' => null,
            'latest_revise_no' => null,
            'tabs' => [],
            'pairs' => [],
            'can_compare' => false,
            'can_view' => false,
            'error' => null,
            'left_label' => 'Selected revision',
            'right_label' => 'Latest',
        ];

        $docNo = trim($docNo);
        if ($docNo === '' || strcasecmp($docNo, 'N/A') === 0) {
            return array_merge($empty, ['error' => 'no_doc_no']);
        }

        if (!DB::table('dcs_masterlist_registration')->where('doc_no', $docNo)->exists()) {
            return array_merge($empty, ['error' => 'not_found']);
        }

        $history = self::history($docNo);
        $revs = $history['revisions'];
        $byId = [];
        foreach ($revs as $rev) {
            $byId[(int) $rev['id']] = $rev;
        }

        $options = [];
        foreach ($revs as $rev) {
            $label = 'Rev ' . $rev['revise_no'];
            if (!empty($rev['is_latest'])) {
                $label .= ' · current';
            }
            if (!empty($rev['doc_no']) && $rev['doc_no'] !== $history['docNo']) {
                $label .= ' · ' . $rev['doc_no'];
            }
            $options[] = [
                'id' => (int) $rev['id'],
                'revise_no' => (int) $rev['revise_no'],
                'is_latest' => (bool) $rev['is_latest'],
                'doc_no' => $rev['doc_no'] ?? null,
                'created_label' => $rev['created_label'] ?? '',
                'label' => $label,
            ];
        }

        // Always lock the right/newer side to the latest revision in the lineage.
        $latest = $revs[0] ?? null;
        $latestId = $latest ? (int) $latest['id'] : null;
        $rightId = $latestId;

        $base = [
            'docNo' => $history['docNo'],
            'docTitle' => $history['docTitle'],
            'options' => $options,
            'prior_options' => array_values(array_filter($options, fn ($o) => (int) $o['id'] !== (int) $latestId)),
            'left_id' => null,
            'right_id' => $rightId,
            'latest_id' => $latestId,
            'latest_revise_no' => $latest ? (int) $latest['revise_no'] : null,
            'tabs' => [],
            'pairs' => [],
            'can_compare' => false,
            'can_view' => false,
            'error' => null,
            'left_label' => 'Selected revision',
            'right_label' => 'Latest',
        ];

        if (!$latest) {
            return array_merge($base, ['error' => 'not_found']);
        }

        $right = $byId[$rightId];
        $rightLabel = 'Rev ' . $right['revise_no'] . ' · latest';

        // Single revision: still show the latest scan (no compare).
        if (count($revs) < 2) {
            $pair = self::reviewPairSection(null, $right, 'masterlist');
            $hasScan = !empty($pair['right_scan']['url']);

            return array_merge($base, [
                'tabs' => $hasScan ? [['key' => 'masterlist', 'label' => 'Masterlist']] : [],
                'pairs' => $hasScan ? ['masterlist' => $pair] : [],
                'can_view' => $hasScan,
                'can_compare' => false,
                'right_label' => $rightLabel,
                'error' => $hasScan ? null : 'need_scan',
            ]);
        }

        // Default left = previous revision; user may pick any older one.
        if ($leftId && isset($byId[$leftId]) && (int) $leftId !== (int) $rightId) {
            // keep requested left
        } else {
            $leftId = (int) $revs[1]['id'];
        }

        if ((int) $leftId === (int) $rightId) {
            $leftId = (int) $revs[1]['id'];
        }

        $left = $byId[$leftId];
        if (!self::reviewRevIsOlder($left, $right)) {
            // If user somehow picked a newer-or-equal, fall back to previous revision.
            $leftId = (int) $revs[1]['id'];
            $left = $byId[$leftId];
        }

        $leftLabel = 'Rev ' . $left['revise_no']
            . (!empty($left['doc_no']) && $left['doc_no'] !== $history['docNo'] ? ' · ' . $left['doc_no'] : '')
            . ' · selected';

        $pair = self::reviewPairSection($left, $right, 'masterlist');
        $hasScan = !empty($pair['left_scan']['url']) || !empty($pair['right_scan']['url']);

        return [
            'docNo' => $history['docNo'],
            'docTitle' => $history['docTitle'],
            'options' => $options,
            'prior_options' => array_values(array_filter($options, fn ($o) => (int) $o['id'] !== (int) $rightId)),
            'left_id' => $leftId,
            'right_id' => $rightId,
            'latest_id' => $latestId,
            'latest_revise_no' => (int) $right['revise_no'],
            'tabs' => $hasScan ? [['key' => 'masterlist', 'label' => 'Masterlist']] : [],
            'pairs' => $hasScan ? ['masterlist' => $pair] : [],
            'can_compare' => true,
            'can_view' => true,
            'error' => $hasScan ? null : 'need_scan',
            'left_label' => $leftLabel,
            'right_label' => $rightLabel,
        ];
    }

    private static function reviewRevIsOlder(array $left, array $right): bool
    {
        $leftRev = (int) ($left['revise_no'] ?? 0);
        $rightRev = (int) ($right['revise_no'] ?? 0);
        if ($leftRev !== $rightRev) {
            return $leftRev < $rightRev;
        }

        return strcmp((string) ($left['created_at'] ?? ''), (string) ($right['created_at'] ?? '')) <= 0;
    }

    private static function reviewPairSection(?array $left, ?array $right, string $key): array
    {
        $leftSection = self::reviewSectionByKey($left, $key);
        $rightSection = self::reviewSectionByKey($right, $key);
        $skip = ['scanned_ml', 'drf_scan', 'dcn_scan', 'dist_scan', 'ret_scan'];

        $rows = [];
        if ($key !== 'syllabi') {
            $leftMap = self::reviewRowMap($leftSection);
            $rightMap = self::reviewRowMap($rightSection);
            $order = [];
            foreach (array_merge(array_keys($leftMap), array_keys($rightMap)) as $rowKey) {
                if (in_array($rowKey, $skip, true) || isset($order[$rowKey])) {
                    continue;
                }
                $order[$rowKey] = true;
            }
            foreach (array_keys($order) as $rowKey) {
                $l = $leftMap[$rowKey] ?? null;
                $r = $rightMap[$rowKey] ?? null;
                if (($l['kind'] ?? $r['kind'] ?? '') === 'offices' || in_array($rowKey, ['sources', 'drf_offices', 'dcn_offices', 'dist_offices', 'ret_offices'], true)) {
                    $rows[] = self::reviewOfficePair($l, $r);
                    continue;
                }
                $leftVal = $l['value'] ?? '—';
                $rightVal = $r['value'] ?? '—';
                $rows[] = array_merge(self::reviewInlineDiff($leftVal, $rightVal), [
                    'key' => $rowKey,
                    'label' => $l['label'] ?? $r['label'] ?? $rowKey,
                ]);
            }
        }

        $syllabi = null;
        if ($key === 'syllabi') {
            $leftTable = $leftSection['table'] ?? self::hstSyllabiTableFromRows($leftSection['rows'] ?? []);
            $rightTable = $rightSection['table'] ?? self::hstSyllabiTableFromRows($rightSection['rows'] ?? []);
            $meta = [];
            $leftMeta = self::reviewRowMap(['rows' => $leftTable['meta'] ?? []]);
            $rightMeta = self::reviewRowMap(['rows' => $rightTable['meta'] ?? []]);
            foreach (['syl_college', 'syl_program', 'syl_sem', 'syl_sy'] as $metaKey) {
                if (!isset($leftMeta[$metaKey]) && !isset($rightMeta[$metaKey])) {
                    continue;
                }
                $l = $leftMeta[$metaKey] ?? null;
                $r = $rightMeta[$metaKey] ?? null;
                $meta[] = array_merge(self::reviewInlineDiff($l['value'] ?? '—', $r['value'] ?? '—'), [
                    'key' => $metaKey,
                    'label' => $l['label'] ?? $r['label'] ?? $metaKey,
                ]);
            }

            $fields = ['course', 'avail', 'copies', 'pages', 'received', 'faculty', 'drf'];
            $leftCourses = [];
            foreach ($leftTable['courses'] ?? [] as $course) {
                $name = $course['course']['compare'] ?? mb_strtolower($course['course']['value'] ?? '');
                $leftCourses[$name !== '' ? $name : uniqid('l.', true)] = $course;
            }
            $rightCourses = [];
            foreach ($rightTable['courses'] ?? [] as $course) {
                $name = $course['course']['compare'] ?? mb_strtolower($course['course']['value'] ?? '');
                $rightCourses[$name !== '' ? $name : uniqid('r.', true)] = $course;
            }
            $names = array_unique(array_merge(array_keys($leftCourses), array_keys($rightCourses)));
            $courses = [];
            foreach ($names as $name) {
                $lc = $leftCourses[$name] ?? null;
                $rc = $rightCourses[$name] ?? null;
                $cells = [];
                $rowStatus = 'same';
                foreach ($fields as $field) {
                    $diff = self::reviewInlineDiff($lc[$field]['value'] ?? '—', $rc[$field]['value'] ?? '—');
                    $cells[$field] = $diff;
                    if ($diff['status'] !== 'same' && $rowStatus === 'same') {
                        $rowStatus = $diff['status'];
                    } elseif ($diff['status'] !== 'same' && $diff['status'] !== $rowStatus) {
                        $rowStatus = 'changed';
                    }
                }
                $courses[] = ['status' => $rowStatus, 'cells' => $cells];
            }
            $syllabi = ['meta' => $meta, 'courses' => $courses];
        }

        $leftScan = self::reviewScanPayload($leftSection);
        $rightScan = self::reviewScanPayload($rightSection);
        $textDiff = self::reviewScanDiff($leftScan, $rightScan);

        return [
            'title' => $leftSection['title'] ?? $rightSection['title'] ?? ucfirst($key),
            'rows' => $rows,
            'syllabi' => $syllabi,
            'left_scan' => $leftScan,
            'right_scan' => $rightScan,
            'text_diff' => $textDiff,
            'scan_status' => $textDiff['status'] ?? 'none',
        ];
    }

    private static function reviewSectionByKey(?array $rev, string $key): ?array
    {
        if (!$rev) {
            return null;
        }
        foreach ($rev['sections'] as $section) {
            if (($section['key'] ?? '') === $key) {
                return $section;
            }
        }

        return null;
    }

    private static function reviewRowMap(?array $section): array
    {
        $map = [];
        foreach ($section['rows'] ?? [] as $row) {
            $map[$row['key']] = $row;
        }

        return $map;
    }

    private static function reviewOfficePair(?array $left, ?array $right): array
    {
        $leftMap = self::reviewOfficeItemMap($left);
        $rightMap = self::reviewOfficeItemMap($right);
        $withCopies = (bool) ($left['with_copies'] ?? $right['with_copies'] ?? false);
        if (!$withCopies) {
            foreach (array_merge($leftMap, $rightMap) as $item) {
                if (($item['copies'] ?? null) !== null) {
                    $withCopies = true;
                    break;
                }
            }
        }

        $names = array_unique(array_merge(array_keys($leftMap), array_keys($rightMap)));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        $offices = [];
        $status = 'same';
        foreach ($names as $key) {
            $l = $leftMap[$key] ?? null;
            $r = $rightMap[$key] ?? null;
            $name = $l['name'] ?? $r['name'] ?? $key;
            $leftCopies = $l['copies'] ?? null;
            $rightCopies = $r['copies'] ?? null;
            $leftPresent = $l !== null;
            $rightPresent = $r !== null;

            if ($leftPresent && $rightPresent) {
                if ($withCopies && (string) $leftCopies !== (string) $rightCopies) {
                    $rowStatus = 'changed';
                    $leftHtml = '<span class="drr-chg">' . e((string) $leftCopies) . '</span>';
                    $rightHtml = '<span class="drr-chg">' . e((string) $rightCopies) . '</span>';
                } else {
                    $rowStatus = 'same';
                    $leftHtml = $withCopies ? e((string) ($leftCopies ?? '1')) : 'Present';
                    $rightHtml = $withCopies ? e((string) ($rightCopies ?? '1')) : 'Present';
                }
            } elseif ($leftPresent) {
                $rowStatus = 'removed';
                $leftHtml = $withCopies
                    ? '<span class="drr-del">' . e((string) ($leftCopies ?? '1')) . '</span>'
                    : '<span class="drr-del">Present</span>';
                $rightHtml = '<span class="drr-del">Removed</span>';
            } else {
                $rowStatus = 'added';
                $leftHtml = '—';
                $rightHtml = $withCopies
                    ? '<span class="drr-ins">' . e((string) ($rightCopies ?? '1')) . '</span>'
                    : '<span class="drr-ins">Present</span>';
            }

            if ($rowStatus !== 'same' && $status === 'same') {
                $status = $rowStatus;
            } elseif ($rowStatus !== 'same' && $rowStatus !== $status) {
                $status = 'changed';
            }

            $offices[] = [
                'name' => $name,
                'status' => $rowStatus,
                'left_html' => $leftHtml,
                'right_html' => $rightHtml,
            ];
        }

        return [
            'key' => $left['key'] ?? $right['key'] ?? 'offices',
            'label' => $left['label'] ?? $right['label'] ?? 'Offices',
            'kind' => 'offices',
            'with_copies' => $withCopies,
            'status' => $status,
            'offices' => $offices,
            'left_html' => '',
            'right_html' => '',
        ];
    }

    private static function reviewOfficeItemMap(?array $row): array
    {
        if (!$row) {
            return [];
        }

        $map = [];
        foreach ($row['items'] ?? [] as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $map[mb_strtolower($name)] = [
                'name' => $name,
                'copies' => $item['copies'] ?? null,
            ];
        }
        if ($map !== []) {
            return $map;
        }

        $value = trim((string) ($row['value'] ?? ''));
        if ($value === '' || $value === '—') {
            return [];
        }

        foreach (preg_split('/\s*,\s*/', $value) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $copies = null;
            $name = $part;
            if (preg_match('/^(.*)\s+\((\d+)\)\s*$/u', $part, $match)) {
                $name = trim($match[1]);
                $copies = $match[2];
            }
            if ($name === '') {
                continue;
            }
            $map[mb_strtolower($name)] = [
                'name' => $name,
                'copies' => $copies,
            ];
        }

        return $map;
    }

    private static function reviewScanPayload(?array $section): array
    {
        $path = $section['scan_path'] ?? null;
        $url = $section['scan_url'] ?? null;
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';

        return [
            'path' => $path,
            'url' => $url,
            'name' => $path ? basename($path) : null,
            'is_pdf' => $ext === 'pdf',
            'text' => '',
            'has_text' => false,
        ];
    }

    private static function reviewScanDiff(array $leftScan, array $rightScan): array
    {
        $leftHas = (bool) ($leftScan['url'] ?? null);
        $rightHas = (bool) ($rightScan['url'] ?? null);
        $leftText = (string) ($leftScan['text'] ?? '');
        $rightText = (string) ($rightScan['text'] ?? '');

        if (!$leftHas && !$rightHas) {
            return [
                'status' => 'none',
                'left_html' => '',
                'right_html' => '',
                'has_text' => false,
                'note' => null,
            ];
        }

        if ($leftText !== '' || $rightText !== '') {
            $diff = self::reviewLineDiff($leftText, $rightText);
            $diff['has_text'] = true;
            $diff['note'] = null;

            return $diff;
        }

        $status = 'same';
        if ($leftHas && !$rightHas) {
            $status = 'removed';
        } elseif (!$leftHas && $rightHas) {
            $status = 'added';
        } elseif (($leftScan['path'] ?? '') !== ($rightScan['path'] ?? '')) {
            $status = 'changed';
        }

        return [
            'status' => $status,
            'left_html' => '',
            'right_html' => '',
            'has_text' => false,
            'note' => 'No extractable text on these scans. Only file presence could be compared.',
        ];
    }

    private static function reviewLineDiff(string $old, string $new): array
    {
        $oldLines = preg_split("/\r\n|\r|\n/", $old) ?: [];
        $newLines = preg_split("/\r\n|\r|\n/", $new) ?: [];
        $oldLines = array_slice($oldLines, 0, 250);
        $newLines = array_slice($newLines, 0, 250);

        if (implode("\n", $oldLines) === implode("\n", $newLines)) {
            $html = e($old !== '' ? $old : '—');

            return [
                'status' => 'same',
                'left_html' => $html,
                'right_html' => $html,
            ];
        }

        $ops = self::reviewLcsOps($oldLines, $newLines);
        $left = '';
        $right = '';
        foreach ($ops as $op) {
            $line = (string) $op['t'];
            if ($op['k'] === 'eq') {
                $left .= e($line) . "\n";
                $right .= e($line) . "\n";
            } elseif ($op['k'] === 'del') {
                $left .= '<span class="drr-del">' . e($line) . "</span>\n";
            } else {
                $right .= '<span class="drr-ins">' . e($line) . "</span>\n";
            }
        }

        $status = 'changed';
        if (trim($old) === '') {
            $status = 'added';
        } elseif (trim($new) === '') {
            $status = 'removed';
        }

        return [
            'status' => $status,
            'left_html' => rtrim($left),
            'right_html' => rtrim($right),
        ];
    }

    private static function reviewInlineDiff(string $old, string $new): array
    {
        $oldPlain = $old === '—' ? '' : $old;
        $newPlain = $new === '—' ? '' : $new;

        if ($oldPlain === $newPlain) {
            $safe = e($old !== '' ? $old : '—');

            return [
                'status' => 'same',
                'left_html' => $safe,
                'right_html' => $safe,
                'left' => $old !== '' ? $old : '—',
                'right' => $new !== '' ? $new : '—',
            ];
        }

        if ($oldPlain === '' && $newPlain !== '') {
            return [
                'status' => 'added',
                'left_html' => '—',
                'right_html' => '<span class="drr-ins">' . e($new) . '</span>',
                'left' => '—',
                'right' => $new,
            ];
        }

        if ($newPlain === '' && $oldPlain !== '') {
            return [
                'status' => 'removed',
                'left_html' => '<span class="drr-del">' . e($old) . '</span>',
                'right_html' => '—',
                'left' => $old,
                'right' => '—',
            ];
        }

        [$leftHtml, $rightHtml] = self::reviewTokenDiffHtml($oldPlain, $newPlain);

        return [
            'status' => 'changed',
            'left_html' => $leftHtml,
            'right_html' => $rightHtml,
            'left' => $old,
            'right' => $new,
        ];
    }

    private static function reviewTokenDiffHtml(string $old, string $new): array
    {
        $a = self::reviewTokens($old);
        $b = self::reviewTokens($new);
        $a = array_slice($a, 0, 800);
        $b = array_slice($b, 0, 800);
        $ops = self::reviewLcsOps($a, $b);

        $left = '';
        $right = '';
        foreach ($ops as $op) {
            $token = e($op['t']);
            if ($op['k'] === 'eq') {
                $left .= $token;
                $right .= $token;
            } elseif ($op['k'] === 'del') {
                $left .= '<span class="drr-del">' . $token . '</span>';
            } else {
                $right .= '<span class="drr-ins">' . $token . '</span>';
            }
        }

        return [trim($left), trim($right)];
    }

    private static function reviewTokens(string $text): array
    {
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        return array_values(array_filter($parts === false ? [] : $parts, fn ($part) => $part !== ''));
    }

    private static function reviewLcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $dp[$i][$j] = $a[$i - 1] === $b[$j - 1]
                    ? $dp[$i - 1][$j - 1] + 1
                    : max($dp[$i - 1][$j], $dp[$i][$j - 1]);
            }
        }

        $ops = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                $ops[] = ['k' => 'eq', 't' => $a[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $dp[$i][$j - 1] >= $dp[$i - 1][$j])) {
                $ops[] = ['k' => 'ins', 't' => $b[$j - 1]];
                $j--;
            } else {
                $ops[] = ['k' => 'del', 't' => $a[$i - 1]];
                $i--;
            }
        }

        return array_reverse($ops);
    }

    private static function hstRow(string $key, string $label, mixed $value, array $extra = []): array
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            $text = '—';
        }

        return array_merge([
            'key' => $key,
            'label' => $label,
            'value' => $text,
            'compare' => mb_strtolower($text === '—' ? '' : $text),
        ], $extra);
    }

    private static function hstDate(mixed $val): string
    {
        return $val ? Carbon::parse($val)->format('M d, Y') : '—';
    }

    private static function hstDateTime(mixed $val): string
    {
        return $val ? Carbon::parse($val)->format('M d, Y · h:i A') : '—';
    }

    private static function hstTime(mixed $val): string
    {
        return $val ? Carbon::parse($val)->format('h:i A') : '—';
    }

    private static function hstFile(mixed $path): string
    {
        return $path ? basename((string) $path) : '—';
    }

    private static function hstOfficeItems($rows, bool $withCopies = false): array
    {
        if (!$rows || (method_exists($rows, 'isEmpty') && $rows->isEmpty())) {
            return [];
        }

        $items = [];
        foreach (collect($rows) as $row) {
            $name = trim((string) ($row->office->office_name ?? $row->office_name ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $items[$key] = [
                'name' => $name,
                'copies' => $withCopies ? (string) max(1, (int) ($row->copies ?? 1)) : null,
            ];
        }

        return array_values($items);
    }

    private static function hstOfficeRow(string $key, string $label, $rows, bool $withCopies = false): array
    {
        $items = self::hstOfficeItems($rows, $withCopies);
        $value = $items === []
            ? '—'
            : collect($items)->map(function ($item) {
                return $item['copies'] !== null ? $item['name'] . ' (' . $item['copies'] . ')' : $item['name'];
            })->implode(', ');

        return self::hstRow($key, $label, $value, [
            'kind' => 'offices',
            'items' => $items,
            'with_copies' => $withCopies,
        ]);
    }

    private static function hstFilledRows(array $rows): array
    {
        return array_values(array_filter($rows, function ($row) {
            $value = trim((string) ($row['value'] ?? ''));

            return $value !== '' && $value !== '—';
        }));
    }

    private static function hstScanMeta(?string $path): array
    {
        $path = $path ? trim($path) : '';
        if ($path === '') {
            return ['scan_path' => null, 'scan_url' => null];
        }

        return [
            'scan_path' => $path,
            'scan_url' => Storage::disk('public')->url($path),
        ];
    }

    private static function hstPushSection(array &$sections, string $key, string $title, array $rows, array $extra = []): void
    {
        $rows = self::hstFilledRows($rows);
        if ($rows === [] && empty($extra['scan_path'])) {
            return;
        }

        $sections[] = array_merge([
            'key' => $key,
            'title' => $title,
            'rows' => $rows,
            'scan_path' => null,
            'scan_url' => null,
        ], $extra);
    }

    private static function buildHistoryRevision(object $ml, ?object $doc, array $lookups, bool $isLatestFlag): array
    {
        $isLatest = ($ml->revision_status ?? '') === 'latest'
            || (($ml->revision_status ?? null) === null && $isLatestFlag);

        $mlHydrated = $doc->masterlistRegistration ?? $ml;
        $drf = $doc->documentRequestForm ?? null;
        $dcn = $doc->documentChangeNotice ?? null;
        $dist = $doc->documentDistribution ?? null;
        $ret = $doc->documentRetrieval ?? null;
        $approvals = $doc->approvalRecords ?? collect();
        $syllabi = $doc->syllabi ?? collect();

        $versionId = $ml->request_version_id ?? $doc->version_id ?? null;
        $creatorId = $ml->request_created_by ?? $ml->created_by ?? null;

        $sections = [];

        self::hstPushSection($sections, 'masterlist', 'Masterlist', [
            self::hstRow('title', 'Title', $ml->doc_title),
            self::hstRow('doc_type', 'Document type', $doc->docType->doc_type_name ?? null),
            self::hstRow('sub_type', 'Sub type', $doc->subType->doc_type_name ?? null),
            self::hstRow('version', 'Version', $lookups['versions'][$versionId] ?? null),
            self::hstRow('revise_no', 'Revision no.', $ml->revise_no === null ? null : (string) $ml->revise_no),
            self::hstRow('registered_at', 'Registered', self::hstDateTime($ml->request_created_at ?? $ml->created_at)),
            self::hstRow('registered_by', 'Registered by', $lookups['creators'][(int) $creatorId] ?? null),
            self::hstRow('originator', 'Originator', $ml->originator_name),
            self::hstOfficeRow('sources', 'Source offices', $mlHydrated->sourceOffices ?? collect()),
            self::hstRow('effectivity', 'Effectivity', self::hstDate($ml->effectivity_date)),
            self::hstRow('deadline', 'Deadline', self::hstDate($ml->deadline)),
            self::hstRow('pages', 'Pages', $ml->no_pages === null ? null : (string) $ml->no_pages),
            self::hstRow('purpose', 'Purpose', $ml->brief_purpose),
            self::hstRow('receipt_date', 'Date received', self::hstDate($ml->doc_receipt_date)),
            self::hstRow('receipt_time', 'Time received', self::hstTime($ml->doc_receipt_time)),
            self::hstRow('reg_date', 'Date registered', self::hstDate($ml->doc_registered_date)),
            self::hstRow('reg_time', 'Time registered', self::hstTime($ml->doc_registered_time)),
            self::hstRow('time_spent', 'Time spent', $ml->time_spent === null ? null : (string) $ml->time_spent),
            self::hstRow('related', 'Related documents', collect($mlHydrated->relatedList ?? [])
                ->map(fn ($r) => trim(($r->doc_title ?? '') . ($r->doc_no ? ' (' . $r->doc_no . ')' : '')))
                ->filter()
                ->unique()
                ->implode(', ')),
            self::hstRow('scanned_ml', 'Scanned masterlist', self::hstFile($ml->scanned_masterlist)),
        ], self::hstScanMeta($ml->scanned_masterlist));

        if ($drf) {
            self::hstPushSection($sections, 'drf', 'Document Request Form', [
                self::hstRow('drf_no', 'DRF no.', $drf->drf_no ?? null),
                self::hstRow('drf_date', 'DRF date', self::hstDate($drf->drf_date ?? null)),
                self::hstRow('drf_receipt_date', 'DRF received date', self::hstDate($drf->drf_receipt_date ?? null)),
                self::hstRow('drf_receipt_time', 'DRF received time', self::hstTime($drf->drf_receipt_time ?? null)),
                self::hstOfficeRow('drf_offices', 'DRF offices', $drf->offices ?? collect()),
                self::hstRow('drf_scan', 'Scanned DRF', self::hstFile($drf->scanned_drf ?? null)),
            ], self::hstScanMeta($drf->scanned_drf ?? null));
        }

        if ($dcn) {
            $dcnRevisionRows = collect($dcn->revisions ?? [])->map(function ($rev) {
                return [
                    'document_no' => $rev->document_no ?: '—',
                    'title' => $rev->title ?: '—',
                    'revision_no' => $rev->revision_no !== null ? (string) $rev->revision_no : '—',
                    'effectivity_date' => self::hstDate($rev->effectivity_date) ?: '—',
                    'brief_purpose' => $rev->brief_purpose ?: '—',
                ];
            })->all();

            $dcnRevText = collect($dcn->revisions ?? [])->map(function ($rev) {
                $bits = array_filter([
                    $rev->title ?: null,
                    $rev->document_no ? 'No. ' . $rev->document_no : null,
                    $rev->revision_no !== null ? 'Rev ' . $rev->revision_no : null,
                    $rev->effectivity_date ? self::hstDate($rev->effectivity_date) : null,
                    $rev->brief_purpose ?: null,
                ]);

                return implode(' · ', $bits);
            })->filter()->implode("\n");

            self::hstPushSection($sections, 'dcn', 'Document Change Notice', [
                self::hstRow('dcn_no', 'DCN no.', $dcn->dcn_no ?? null),
                self::hstRow('dcn_date', 'DCN date', self::hstDate($dcn->dcn_date ?? null)),
                self::hstRow('dcn_receipt_date', 'DCN received date', self::hstDate($dcn->dcn_receipt_date ?? null)),
                self::hstRow('dcn_receipt_time', 'DCN received time', self::hstTime($dcn->dcn_receipt_time ?? null)),
                self::hstOfficeRow('dcn_offices', 'DCN offices', $dcn->offices ?? collect()),
                self::hstRow('dcn_scan', 'Scanned DCN', self::hstFile($dcn->scanned_dcn ?? null)),
                self::hstRow('dcn_revs', 'Change items', $dcnRevText),
            ], array_merge(self::hstScanMeta($dcn->scanned_dcn ?? null), [
                'revisions' => $dcnRevisionRows,
            ]));
        }

        if ($dist) {
            self::hstPushSection($sections, 'distribution', 'Distribution', [
                self::hstRow('dist_date_actual', 'Actual date', self::hstDate($dist->doc_distribution_date_actual ?? null)),
                self::hstRow('dist_time_actual', 'Actual time', self::hstTime($dist->doc_distribution_time_actual ?? null)),
                self::hstRow('dist_date_file', 'File date', self::hstDate($dist->doc_distribution_date_file ?? null)),
                self::hstRow('dist_time_file', 'File time', self::hstTime($dist->doc_distribution_time_file ?? null)),
                self::hstRow('dist_spent', 'Time spent', $dist->time_spent ?? null),
                self::hstOfficeRow('dist_offices', 'Offices / copies', $dist->offices ?? collect(), true),
                self::hstRow('dist_remarks', 'Remarks', $dist->remarks ?? null),
                self::hstRow('dist_scan', 'Scanned copy', self::hstFile($dist->scanned_distribution ?? null)),
            ], self::hstScanMeta($dist->scanned_distribution ?? null));
        }

        if ($ret) {
            self::hstPushSection($sections, 'retrieval', 'Retrieval', [
                self::hstRow('ret_date_actual', 'Actual date', self::hstDate($ret->doc_retrieval_date_actual ?? null)),
                self::hstRow('ret_time_actual', 'Actual time', self::hstTime($ret->doc_retrieval_time_actual ?? null)),
                self::hstRow('ret_date_file', 'File date', self::hstDate($ret->doc_retrieval_date_file ?? null)),
                self::hstRow('ret_time_file', 'File time', self::hstTime($ret->doc_retrieval_time_file ?? null)),
                self::hstRow('ret_spent', 'Time spent', $ret->time_spent ?? null),
                self::hstOfficeRow('ret_offices', 'Offices / copies', $ret->offices ?? collect(), true),
                self::hstRow('ret_remarks', 'Remarks', $ret->remarks ?? null),
                self::hstRow('ret_scan', 'Scanned copy', self::hstFile($ret->scanned_retrieval ?? null)),
            ], self::hstScanMeta($ret->scanned_retrieval ?? null));
        }

        $approvalText = collect($approvals)->map(function ($a) use ($lookups) {
            $body = $lookups['approvals'][$a->approval_body_id] ?? null;
            $bits = array_filter([
                $body,
                $a->approval_no ? 'No. ' . $a->approval_no : null,
                $a->approval_date ? self::hstDate($a->approval_date) : null,
            ]);

            return implode(' · ', $bits);
        })->filter()->implode('; ');

        self::hstPushSection($sections, 'approval', 'Approval', [
            self::hstRow('approval_status', 'Approval status', $ml->approval_status ?? $doc->approval_status ?? null),
            self::hstRow('approval_records', 'Approval records', $approvalText),
        ]);

        if ($syllabi->isNotEmpty()) {
            $first = $syllabi->first();
            $metaRows = self::hstFilledRows([
                self::hstRow('syl_college', 'College', $lookups['colleges'][$first->college_id] ?? null),
                self::hstRow('syl_program', 'Program', $lookups['programs'][$first->program_id] ?? null),
                self::hstRow('syl_sem', 'Semester', $lookups['semesters'][$first->semester_id] ?? null),
                self::hstRow('syl_sy', 'School year', $lookups['years'][$first->school_year_id] ?? null),
            ]);

            $courseRows = [];
            foreach ($syllabi as $idx => $syl) {
                $code = $syl->course->course_code ?? $syl->course_code ?? '';
                $name = $syl->course->course_name ?? $syl->course_name ?? '';
                $label = trim($code . ' ' . $name) ?: ('Course ' . ($idx + 1));
                $prefix = 'syl.' . ($syl->course_id ?? $syl->id);
                $faculties = collect($syl->drfs ?? [])->pluck('faculty_name')->filter()->unique()->implode(', ');
                $drfBits = collect($syl->drfs ?? [])->map(function ($d) {
                    if (!self::pgBool($d->is_drf_available ?? false)) {
                        return null;
                    }

                    return trim(($d->faculty_name ? $d->faculty_name . ': ' : '') . ($d->drf_no ?: 'DRF') .
                        ($d->drf_date ? ' (' . self::hstDate($d->drf_date) . ')' : ''));
                })->filter()->implode('; ');

                $recv = [];
                if ($syl->date_received) {
                    $recv[] = self::hstDate($syl->date_received);
                }
                if ($syl->time_received) {
                    $recv[] = self::hstTime($syl->time_received);
                }

                $courseRows[] = self::hstRow($prefix . '.course', 'Course', $label);
                $courseRows[] = self::hstRow($prefix . '.avail', 'Available', self::pgBool($syl->is_available) ? 'Yes' : 'No');
                $courseRows[] = self::hstRow($prefix . '.copies', 'Copies', (string) ($syl->no_copies ?? 1));
                $courseRows[] = self::hstRow($prefix . '.pages', 'Pages', $syl->no_pages === null ? null : (string) $syl->no_pages);
                $courseRows[] = self::hstRow($prefix . '.received', 'Received', implode(' ', $recv));
                $courseRows[] = self::hstRow($prefix . '.faculty', 'Faculty', $faculties);
                $courseRows[] = self::hstRow($prefix . '.drf', 'DRF', $drfBits);
            }

            $sections[] = [
                'key' => 'syllabi',
                'title' => 'Syllabi',
                'rows' => array_merge($metaRows, $courseRows),
                'table' => ['meta' => $metaRows, 'courses' => []],
                'scan_path' => null,
                'scan_url' => null,
            ];
        }

        $tabLabels = [
            'masterlist' => 'Masterlist',
            'drf' => 'DRF',
            'dcn' => 'DCN',
            'distribution' => 'Distribution',
            'retrieval' => 'Retrieval',
            'approval' => 'Approval',
            'syllabi' => 'Syllabi',
        ];
        $checklists = [];
        foreach ($sections as $section) {
            if (isset($tabLabels[$section['key']])) {
                $checklists[] = [
                    'key' => $section['key'],
                    'label' => $tabLabels[$section['key']],
                ];
            }
        }

        return [
            'id' => (int) $ml->request_id,
            'doc_no' => $ml->doc_no ?: null,
            'revise_no' => (int) ($ml->revise_no ?? 0),
            'is_latest' => $isLatest,
            'created_at' => $ml->request_created_at ?? $ml->created_at,
            'created_label' => self::hstDateTime($ml->request_created_at ?? $ml->created_at),
            'checklists' => $checklists,
            'sections' => $sections,
            'summary' => [],
            'changes' => [],
        ];
    }

    private static function hstSyllabiTableFromRows(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['key']] = $row;
        }

        $meta = [];
        foreach (['syl_college', 'syl_program', 'syl_sem', 'syl_sy'] as $key) {
            if (isset($byKey[$key])) {
                $meta[] = $byKey[$key];
            }
        }

        $prefixes = [];
        foreach ($rows as $row) {
            if (preg_match('/^(syl\.[^.]+)\./', $row['key'] ?? '', $match)) {
                $prefixes[$match[1]] = true;
            }
        }

        $fields = ['course', 'avail', 'copies', 'pages', 'received', 'faculty', 'drf'];
        $empty = ['value' => '—', 'changed' => false, 'previous' => null];
        $courses = [];
        foreach (array_keys($prefixes) as $prefix) {
            $course = [];
            foreach ($fields as $field) {
                $course[$field] = $byKey[$prefix . '.' . $field] ?? $empty;
            }
            $courses[] = $course;
        }

        return [
            'meta' => $meta,
            'courses' => $courses,
        ];
    }

    private static function hstPickRows(array $sections, array $keys): array
    {
        $found = [];
        foreach ($sections as $section) {
            foreach ($section['rows'] as $row) {
                $found[$row['key']] = $row;
            }
        }

        $picked = [];
        foreach ($keys as $key) {
            if (isset($found[$key])) {
                $picked[] = $found[$key];
            }
        }

        return $picked;
    }

    public static function searchDocuments(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 1) {
            return [];
        }

        $visibleIds = self::visibleRequestIds();
        $field = $request->input('field');
        $docTypeId = $request->input('doc_type_id');
        $subTypeId = $request->input('sub_type_id');
        $allRevisions = $request->boolean('all_revisions');
        $hasKeywords = Schema::hasColumn('dcs_masterlist_registration', 'keywords');
        // Dashboard search (no field filter): also match keywords/title/doc_no on prior revisions,
        // then resolve hits to the current lineage tip so the user opens the latest document.
        $dashboardSearch = !$allRevisions && ($field === null || $field === '');

        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->whereIn('ml.request_id', $visibleIds);

        if (!$allRevisions && !$dashboardSearch) {
            $query->where(function ($qr) {
                $qr->whereNull('ml.revision_status')
                    ->orWhere('ml.revision_status', '')
                    ->orWhere('ml.revision_status', 'latest');
            });
        }

        if ($docTypeId) {
            $query->where('dr.doc_type_id', $docTypeId);
            if ($subTypeId) {
                $query->where('dr.sub_type_id', $subTypeId);
            }
        }

        $query->where(function ($qr) use ($q, $field, $hasKeywords) {
            if ($field === 'no') {
                $qr->where('ml.doc_no', 'ilike', "%{$q}%");
            } elseif ($field === 'title') {
                $qr->where('ml.doc_title', 'ilike', "%{$q}%");
            } else {
                $qr->where('ml.doc_title', 'ilike', "%{$q}%")
                    ->orWhere('ml.doc_no', 'ilike', "%{$q}%");
                if ($hasKeywords) {
                    $qr->orWhere('ml.keywords', 'ilike', "%{$q}%");
                }
            }
        });

        if ($request->filled('exclude_request_id')) {
            $query->where('ml.request_id', '!=', $request->exclude_request_id);
        }

        $select = [
            'ml.id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'ml.effectivity_date',
            'ml.brief_purpose',
            'ml.scanned_masterlist',
            'ml.revision_status',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name as type_name',
            'st.doc_type_name as sub_type_name',
        ];
        if ($hasKeywords) {
            $select[] = 'ml.keywords';
        }
        if (Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            $select[] = 'ml.revised_from_doc_no';
        }

        $rows = $query->orderByDesc('ml.revise_no')
            ->orderBy('ml.doc_no')
            ->orderBy('ml.doc_title')
            ->limit($allRevisions ? 50 : ($dashboardSearch ? 60 : 30))
            ->get($select);

        if ($rows->isEmpty()) {
            return [];
        }

        if ($dashboardSearch) {
            $rows = self::resolveSearchHitsToLineageTips($rows, $visibleIds, $hasKeywords);
        } elseif (!$allRevisions) {
            $seen = [];
            $rows = $rows->filter(function ($m) use (&$seen) {
                $key = strtolower(trim((string) ($m->doc_no ?? ''))) . '|'
                    . (int) ($m->doc_type_id ?? 0) . '|'
                    . (int) ($m->sub_type_id ?? 0);
                if (isset($seen[$key])) {
                    return false;
                }
                $seen[$key] = true;

                return true;
            })->values();
        }

        $rows = $rows->take(15);

        $requestIds = $rows->pluck('request_id')->all();
        $drfIds = DB::table('dcs_document_request_form')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $dcnIds = DB::table('dcs_document_change_notice')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $distIds = DB::table('dcs_document_distribution')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $retIds = DB::table('dcs_document_retrieval')->whereIn('request_id', $requestIds)->pluck('request_id')->flip();
        $approvalIds = DB::table('dcs_document_requests')
            ->whereIn('id', $requestIds)
            ->where('approval_status', 'applicable')
            ->pluck('id')
            ->flip();

        return $rows->map(function ($m) use ($drfIds, $dcnIds, $distIds, $retIds, $approvalIds) {
            $docNo = $m->doc_no ?: 'No number';
            $title = $m->doc_title ?: 'Untitled';

            return [
                'masterlist_id' => $m->id,
                'request_id' => $m->request_id,
                'doc_no' => $m->doc_no,
                'doc_title' => $m->doc_title,
                'type_name' => $m->type_name,
                'sub_type_name' => $m->sub_type_name,
                'revise_no' => $m->revise_no,
                'effectivity_date' => $m->effectivity_date ? Carbon::parse($m->effectivity_date)->format('Y-m-d') : null,
                'brief_purpose' => $m->brief_purpose,
                'keywords' => $m->keywords ?? null,
                'scanned_copy_url' => $m->scanned_masterlist ? Storage::disk('public')->url($m->scanned_masterlist) : null,
                'scanned_copy_path' => $m->scanned_masterlist,
                'label' => $docNo . ' — ' . $title . ' (Rev ' . (int) $m->revise_no . ')',
                'checklists' => [
                    'drf' => isset($drfIds[$m->request_id]),
                    'dcn' => isset($dcnIds[$m->request_id]),
                    'masterlist' => true,
                    'approval' => isset($approvalIds[$m->request_id]),
                    'distribution' => isset($distIds[$m->request_id]),
                    'retrieval' => isset($retIds[$m->request_id]),
                ],
                'edit_url' => route('dcs.register.edit', $m->request_id, false),
                'stamp_url' => route('dcs.stamping.index', self::documentSearchParams($m), false),
                'inventory_url' => route('dcs.database.index', self::documentSearchParams($m), false),
                'match_request_id' => isset($m->match_request_id) ? (int) $m->match_request_id : null,
                'match_revise_no' => isset($m->match_revise_no) ? (int) $m->match_revise_no : null,
                'match_masterlist_id' => isset($m->match_masterlist_id) ? (int) $m->match_masterlist_id : null,
            ];
        })
            ->values()
            ->all();
    }

    /**
     * Map search hits (including obsolete / prior-renumber rows) to the current lineage tip,
     * so Dashboard keyword search still finds documents after revision/renumber.
     */
    private static function resolveSearchHitsToLineageTips(Collection $rows, array $visibleIds, bool $hasKeywords): Collection
    {
        $tips = collect();
        $seenTipKeys = [];

        foreach ($rows as $hit) {
            $tip = self::resolveLineageTipMasterlist($hit, $visibleIds);
            if (!$tip) {
                continue;
            }

            $key = strtolower(trim((string) ($tip->doc_no ?? ''))) . '|'
                . (int) ($tip->doc_type_id ?? 0) . '|'
                . (int) ($tip->sub_type_id ?? 0);
            if (isset($seenTipKeys[$key])) {
                continue;
            }
            $seenTipKeys[$key] = true;

            $enriched = self::loadSearchDocumentRow((int) $tip->id, $hasKeywords);
            if ($enriched) {
                // Preserve the revision that actually matched the search (may be an older renumber/revision).
                $enriched->match_request_id = (int) $hit->request_id;
                $enriched->match_revise_no = (int) ($hit->revise_no ?? 0);
                $enriched->match_masterlist_id = (int) $hit->id;
                $tips->push($enriched);
            }
        }

        return $tips->values();
    }

    private static function resolveLineageTipMasterlist(object $hit, array $visibleIds): ?object
    {
        $docTypeId = (int) ($hit->doc_type_id ?? 0);
        $subTypeId = !empty($hit->sub_type_id) ? (int) $hit->sub_type_id : null;
        $docNo = trim((string) ($hit->doc_no ?? ''));
        if ($docNo === '' || $docTypeId < 1) {
            return null;
        }

        $currentNo = $docNo;
        $seen = [];

        // Walk forward along renumber links: prior → newer tip.
        if (Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
            while ($currentNo !== '' && !isset($seen[strtolower($currentNo)])) {
                $seen[strtolower($currentNo)] = true;

                $nextQuery = DB::table('dcs_masterlist_registration as ml')
                    ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
                    ->whereIn('ml.request_id', $visibleIds)
                    ->where('ml.revised_from_doc_no', $currentNo)
                    ->where('dr.doc_type_id', $docTypeId);

                if ($subTypeId) {
                    $nextQuery->where('dr.sub_type_id', $subTypeId);
                } else {
                    $nextQuery->whereNull('dr.sub_type_id');
                }

                $next = $nextQuery
                    ->orderByDesc('ml.revise_no')
                    ->orderByDesc('ml.id')
                    ->select('ml.doc_no', 'dr.doc_type_id', 'dr.sub_type_id')
                    ->first();

                if (!$next || !trim((string) $next->doc_no)) {
                    break;
                }
                $currentNo = trim((string) $next->doc_no);
            }
        }

        $latest = self::latestMasterlistForDocNo($currentNo, $docTypeId, $subTypeId, $visibleIds);
        if ($latest) {
            $latest->doc_type_id = $docTypeId;
            $latest->sub_type_id = $subTypeId;
        }

        return $latest;
    }

    private static function loadSearchDocumentRow(int $masterlistId, bool $hasKeywords): ?object
    {
        $select = [
            'ml.id',
            'ml.request_id',
            'ml.doc_no',
            'ml.doc_title',
            'ml.revise_no',
            'ml.effectivity_date',
            'ml.brief_purpose',
            'ml.scanned_masterlist',
            'ml.revision_status',
            'dr.doc_type_id',
            'dr.sub_type_id',
            'dt.doc_type_name as type_name',
            'st.doc_type_name as sub_type_name',
        ];
        if ($hasKeywords) {
            $select[] = 'ml.keywords';
        }

        return DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->where('ml.id', $masterlistId)
            ->select($select)
            ->first();
    }

    public static function documentRevisions(Request $request): array
    {
        $requestId = (int) $request->input('request_id');
        if ($requestId < 1) {
            return [];
        }

        $visibleIds = self::visibleRequestIds();
        if (!in_array($requestId, $visibleIds, true)) {
            return [];
        }

        $anchor = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->where('ml.request_id', $requestId)
            ->select('ml.doc_no', 'dr.doc_type_id', 'dr.sub_type_id')
            ->first();

        if (!$anchor || !trim((string) ($anchor->doc_no ?? ''))) {
            return [];
        }

        $docNo = trim((string) $request->input('doc_no', ''));
        if ($docNo === '') {
            $docNo = trim((string) $anchor->doc_no);
        }

        $docTypeId = (int) $anchor->doc_type_id;
        $subTypeId = $anchor->sub_type_id ? (int) $anchor->sub_type_id : null;
        $chain = self::buildRenumberChain($docNo, $docTypeId, $subTypeId, $visibleIds);

        $merged = [];
        foreach ($chain as $chainDocNo) {
            foreach (self::masterlistRevisionsForDocNo($chainDocNo, $docTypeId, $subTypeId, $visibleIds) as $row) {
                $merged[] = $row;
            }
        }

        return $merged;
    }

    /** @return list<string> Current doc no first, then each prior renumbered doc no. */
    private static function buildRenumberChain(string $docNo, int $docTypeId, ?int $subTypeId, array $visibleIds): array
    {
        $docNo = trim($docNo);
        if ($docNo === '') {
            return [];
        }

        $chain = [];
        $seen = [];
        $current = $docNo;

        while ($current !== '' && !isset($seen[strtolower($current)])) {
            $seen[strtolower($current)] = true;
            $chain[] = $current;

            $latest = self::latestMasterlistForDocNo($current, $docTypeId, $subTypeId, $visibleIds);
            if (!$latest || !Schema::hasColumn('dcs_masterlist_registration', 'revised_from_doc_no')) {
                break;
            }

            $from = trim((string) ($latest->revised_from_doc_no ?? ''));
            if ($from === '' || strcasecmp($from, $current) === 0) {
                break;
            }

            $current = $from;
        }

        return $chain;
    }

    private static function latestMasterlistForDocNo(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds
    ): ?object {
        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->where('ml.doc_no', $docNo)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        return $query
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->select('ml.*')
            ->first();
    }

    /** @return list<array<string, mixed>> */
    private static function masterlistRevisionsForDocNo(
        string $docNo,
        int $docTypeId,
        ?int $subTypeId,
        array $visibleIds
    ): array {
        $query = DB::table('dcs_masterlist_registration as ml')
            ->join('dcs_document_requests as dr', 'dr.id', '=', 'ml.request_id')
            ->whereIn('ml.request_id', $visibleIds)
            ->where('ml.doc_no', $docNo)
            ->where('dr.doc_type_id', $docTypeId);

        if ($subTypeId) {
            $query->where('dr.sub_type_id', $subTypeId);
        } else {
            $query->whereNull('dr.sub_type_id');
        }

        return $query
            ->orderByDesc('ml.revise_no')
            ->orderByDesc('ml.id')
            ->get([
                'ml.id as masterlist_id',
                'ml.request_id',
                'ml.doc_no',
                'ml.doc_title',
                'ml.revise_no',
                'ml.revision_status',
                'ml.effectivity_date',
                'ml.brief_purpose',
                'ml.scanned_masterlist',
            ])
            ->map(function ($row) {
                // Prefer DCN Justification from that registration for Documents for Revision "Brief Purpose".
                $dcnPurpose = DB::table('dcs_document_change_notice')
                    ->where('request_id', $row->request_id)
                    ->value('brief_purpose');
                if ($dcnPurpose === null || trim((string) $dcnPurpose) === '') {
                    $dcnPurpose = DB::table('dcs_doc_revision as rev')
                        ->join('dcs_document_change_notice as dcn', 'dcn.id', '=', 'rev.dcn_id')
                        ->where('dcn.request_id', $row->request_id)
                        ->where('rev.document_no', $row->doc_no)
                        ->orderByDesc('rev.id')
                        ->value('rev.brief_purpose');
                }
                $row->brief_purpose = ($dcnPurpose !== null && trim((string) $dcnPurpose) !== '')
                    ? trim((string) $dcnPurpose)
                    : $row->brief_purpose;

                return self::mapDocumentRevisionRow($row);
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private static function mapDocumentRevisionRow(object $row): array
    {
        return [
            'masterlist_id' => $row->masterlist_id,
            'request_id' => $row->request_id,
            'doc_no' => $row->doc_no,
            'doc_title' => $row->doc_title,
            'revise_no' => (int) $row->revise_no,
            'revision_status' => $row->revision_status ?: 'latest',
            'effectivity_date' => $row->effectivity_date
                ? Carbon::parse($row->effectivity_date)->format('Y-m-d')
                : null,
            'brief_purpose' => $row->brief_purpose,
            'scanned_copy_url' => $row->scanned_masterlist
                ? Storage::disk('public')->url($row->scanned_masterlist)
                : null,
            'scanned_copy_path' => $row->scanned_masterlist,
            'label' => ($row->doc_no ?: 'No number') . ' — Rev ' . (int) $row->revise_no
                . (($row->revision_status ?? '') === 'obsolete' ? ' (Obsolete)' : ''),
        ];
    }

    public static function documentChecklistPreview(int $requestId, string $type): array
    {
        $allowed = ['drf', 'dcn', 'masterlist', 'approval', 'distribution', 'retrieval'];
        if (!in_array($type, $allowed, true)) {
            abort(404);
        }

        $visibleIds = self::visibleRequestIds();
        if (!in_array((int) $requestId, $visibleIds, true)) {
            abort(404);
        }

        $preview = match ($type) {
            'drf' => self::previewDrf($requestId),
            'dcn' => self::previewDcn($requestId),
            'masterlist' => self::previewMasterlist($requestId),
            'approval' => self::previewApproval($requestId),
            'distribution' => self::previewDistribution($requestId),
            'retrieval' => self::previewRetrieval($requestId),
        };

        $preview['fields'] = array_merge(self::previewTypeFields($requestId), $preview['fields'] ?? []);

        return $preview;
    }

    private static function previewTypeFields(int $requestId): array
    {
        $row = DB::table('dcs_document_requests as dr')
            ->leftJoin('dcs_doc_types as dt', 'dt.id', '=', 'dr.doc_type_id')
            ->leftJoin('dcs_doc_types as st', 'st.id', '=', 'dr.sub_type_id')
            ->where('dr.id', $requestId)
            ->first(['dt.doc_type_name as type_name', 'st.doc_type_name as sub_type_name']);

        $fields = [];
        if (!empty($row?->type_name)) {
            $fields[] = self::previewField('Document Type', $row->type_name);
        }
        if (!empty($row?->sub_type_name)) {
            $fields[] = self::previewField('Sub Type', $row->sub_type_name);
        }

        return $fields;
    }

    private static function previewField(string $label, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['label' => $label, 'value' => '—'];
        }

        return ['label' => $label, 'value' => (string) $value];
    }

    private static function previewDrf(int $requestId): array
    {
        $drf = DB::table('dcs_document_request_form')->where('request_id', $requestId)->first();
        if (!$drf) {
            abort(404);
        }

        $offices = DB::table('dcs_drf_offices as d')
            ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
            ->where('d.document_request_form_id', $drf->id)
            ->pluck('o.office_name')
            ->filter()
            ->values()
            ->all();

        return [
            'title' => 'Document Request Form',
            'type' => 'drf',
            'doc_no' => null,
            'doc_title' => $drf->doc_title,
            'fields' => [
                self::previewField('DRF No.', $drf->drf_no),
                self::previewField('DRF Date', self::formatDate($drf->drf_date)),
                self::previewField('Receipt Date', self::formatDate($drf->drf_receipt_date)),
                self::previewField('Receipt Time', self::formatTime($drf->drf_receipt_time)),
                self::previewField('Document Title', $drf->doc_title),
            ],
            'sections' => $offices !== []
                ? [['heading' => 'Source Offices', 'items' => $offices]]
                : [],
        ];
    }

    private static function previewDcn(int $requestId): array
    {
        $dcn = DB::table('dcs_document_change_notice')->where('request_id', $requestId)->first();
        if (!$dcn) {
            abort(404);
        }

        $offices = DB::table('dcs_dcn_offices as d')
            ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
            ->where('d.dcn_id', $dcn->id)
            ->pluck('o.office_name')
            ->filter()
            ->values()
            ->all();

        if ($offices === [] && $dcn->office_id) {
            $officeName = DB::table('office')->where('id', $dcn->office_id)->value('office_name');
            if ($officeName) {
                $offices = [$officeName];
            }
        }

        $revisions = DB::table('dcs_doc_revision')
            ->where('dcn_id', $dcn->id)
            ->orderBy('id')
            ->get()
            ->map(fn ($rev) => [
                'title' => $rev->title ?: '—',
                'document_no' => $rev->document_no ?: '—',
                'revision_no' => $rev->revision_no !== null ? (string) $rev->revision_no : '—',
                'effectivity_date' => self::formatDate($rev->effectivity_date) ?: '—',
                'brief_purpose' => $rev->brief_purpose ?: '—',
            ])
            ->all();

        return [
            'title' => 'Document Change Notice',
            'type' => 'dcn',
            'doc_no' => $dcn->dcn_no,
            'doc_title' => null,
            'fields' => [
                self::previewField('DCN No.', $dcn->dcn_no),
                self::previewField('DCN Date', self::formatDate($dcn->dcn_date)),
                self::previewField('Receipt Date', self::formatDate($dcn->dcn_receipt_date)),
                self::previewField('Receipt Time', self::formatTime($dcn->dcn_receipt_time)),
            ],
            'sections' => array_values(array_filter([
                $offices !== [] ? ['heading' => 'Offices', 'items' => $offices] : null,
                $revisions !== [] ? ['heading' => 'Revisions', 'revisions' => $revisions] : null,
            ])),
        ];
    }

    private static function previewMasterlist(int $requestId): array
    {
        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $requestId)->first();
        if (!$ml) {
            abort(404);
        }

        $offices = DB::table('dcs_masterlist_source_offices as s')
            ->leftJoin('office as o', 'o.id', '=', 's.office_id')
            ->where('s.masterlist_id', $ml->id)
            ->pluck('o.office_name')
            ->filter()
            ->values()
            ->all();

        return [
            'title' => 'Masterlist Registration',
            'type' => 'masterlist',
            'doc_no' => $ml->doc_no,
            'doc_title' => $ml->doc_title,
            'fields' => [
                self::previewField('Document No.', $ml->doc_no),
                self::previewField('Document Title', $ml->doc_title),
                self::previewField('Revision No.', $ml->revise_no),
                self::previewField('Receipt Date', self::formatDate($ml->doc_receipt_date)),
                self::previewField('Receipt Time', self::formatTime($ml->doc_receipt_time)),
                self::previewField('Registered Date', self::formatDate($ml->doc_registered_date)),
                self::previewField('Registered Time', self::formatTime($ml->doc_registered_time)),
                self::previewField('Effectivity Date', self::formatDate($ml->effectivity_date)),
                self::previewField('No. of Pages', $ml->no_pages),
                self::previewField('Originator', $ml->originator_name),
                self::previewField('Deadline', self::formatDate($ml->deadline)),
                self::previewField('Time Spent (mins)', $ml->time_spent),
                self::previewField('Brief Purpose', $ml->brief_purpose),
            ],
            'sections' => $offices !== []
                ? [['heading' => 'Source Offices', 'items' => $offices]]
                : [],
        ];
    }

    private static function previewApproval(int $requestId): array
    {
        $doc = DB::table('dcs_document_requests')->where('id', $requestId)->first();
        if (!$doc || ($doc->approval_status ?? '') !== 'applicable') {
            abort(404);
        }

        $approval = DB::table('dcs_approval_records as ar')
            ->leftJoin('dcs_approval_body as ab', 'ab.id', '=', 'ar.approval_body_id')
            ->where('ar.request_id', $requestId)
            ->first(['ab.approval_name', 'ar.approval_date', 'ar.approval_no']);

        return [
            'title' => 'Approving Body',
            'type' => 'approval',
            'doc_no' => null,
            'doc_title' => null,
            'fields' => [
                self::previewField('Approving Body', $approval?->approval_name),
                self::previewField('Approval Date', self::formatDate($approval?->approval_date)),
                self::previewField('Approval No.', $approval?->approval_no),
            ],
            'sections' => [],
        ];
    }

    private static function previewDistribution(int $requestId): array
    {
        $dist = DB::table('dcs_document_distribution')->where('request_id', $requestId)->first();
        if (!$dist) {
            abort(404);
        }

        $offices = DB::table('dcs_distribution_offices as d')
            ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
            ->where('d.distribution_id', $dist->id)
            ->get(['o.office_name', 'd.copies'])
            ->map(fn ($row) => ($row->office_name ?: 'Unknown') . ' (' . (int) $row->copies . ' ' . ((int) $row->copies === 1 ? 'copy' : 'copies') . ')')
            ->values()
            ->all();

        return [
            'title' => 'Document Distribution',
            'type' => 'distribution',
            'doc_no' => null,
            'doc_title' => null,
            'fields' => [
                self::previewField('Distribution Date (Actual)', self::formatDate($dist->doc_distribution_date_actual)),
                self::previewField('Distribution Time (Actual)', self::formatTime($dist->doc_distribution_time_actual)),
                self::previewField('Distribution Date (File)', self::formatDate($dist->doc_distribution_date_file)),
                self::previewField('Distribution Time (File)', self::formatTime($dist->doc_distribution_time_file)),
                self::previewField('Time Spent (mins)', $dist->time_spent),
                self::previewField('Remarks', $dist->remarks),
            ],
            'sections' => $offices !== []
                ? [['heading' => 'Distribution Offices', 'items' => $offices]]
                : [],
        ];
    }

    private static function previewRetrieval(int $requestId): array
    {
        $ret = DB::table('dcs_document_retrieval')->where('request_id', $requestId)->first();
        if (!$ret) {
            abort(404);
        }

        $offices = DB::table('dcs_retrieval_offices as r')
            ->leftJoin('office as o', 'o.id', '=', 'r.office_id')
            ->where('r.retrieval_id', $ret->id)
            ->get(['o.office_name', 'r.copies'])
            ->map(fn ($row) => ($row->office_name ?: 'Unknown') . ' (' . (int) $row->copies . ' ' . ((int) $row->copies === 1 ? 'copy' : 'copies') . ')')
            ->values()
            ->all();

        return [
            'title' => 'Document Retrieval',
            'type' => 'retrieval',
            'doc_no' => null,
            'doc_title' => null,
            'fields' => [
                self::previewField('Retrieval Date (Actual)', self::formatDate($ret->doc_retrieval_date_actual)),
                self::previewField('Retrieval Time (Actual)', self::formatTime($ret->doc_retrieval_time_actual)),
                self::previewField('Retrieval Date (File)', self::formatDate($ret->doc_retrieval_date_file)),
                self::previewField('Retrieval Time (File)', self::formatTime($ret->doc_retrieval_time_file)),
                self::previewField('Time Spent (mins)', $ret->time_spent),
                self::previewField('Remarks', $ret->remarks),
            ],
            'sections' => $offices !== []
                ? [['heading' => 'Retrieval Offices', 'items' => $offices]]
                : [],
        ];
    }

    public static function checkDocNo(Request $request)
    {
        $docNo = $request->input('doc_no');
        $docTypeId = (int) $request->input('doc_type_id');
        $subTypeId = $request->input('sub_type_id');
        $excludeRequestId = (int) $request->input('exclude_request_id', 0);

        if (!$docNo) {
            return ['exists' => false, 'message' => 'No document number provided.'];
        }

        $result = RegisterPersistHelper::findMatchingRegistrationRows(
            $docNo,
            $docTypeId,
            $subTypeId ? (int) $subTypeId : null
        );

        if ($result['found']) {
            $matches = $result['matches'];
            if ($excludeRequestId > 0) {
                $matches = $matches->filter(fn ($row) => (int) $row->id !== $excludeRequestId)->values();
            }
            if ($matches->isEmpty()) {
                return [
                    'exists' => false,
                    'is_self' => true,
                    'message' => 'This is the current document number.',
                    'next_rev' => null,
                ];
            }
            $result['matches'] = $matches;
            $latest = DB::table('dcs_masterlist_registration')
                ->whereIn('request_id', $matches->pluck('id'))
                ->where('doc_no', $docNo)
                ->orderByDesc('revise_no')
                ->first();
            $result['latest'] = $latest;
            if ($latest) {
                $latestRev = (int) $latest->revise_no;
                $registrations = DB::table('dcs_masterlist_registration')
                    ->whereIn('request_id', $result['matches']->pluck('id'))
                    ->where('doc_no', $docNo)
                    ->orderByDesc('revise_no')
                    ->get();

                $latestDistribution = DB::table('dcs_document_distribution')
                    ->where('request_id', $latest->request_id)
                    ->first();
                $latestDistributionOffices = [];
                if ($latestDistribution) {
                    $latestDistributionOffices = DB::table('dcs_distribution_offices as d')
                        ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
                        ->where('d.distribution_id', $latestDistribution->id)
                        ->orderBy('d.sort_order')
                        ->orderBy('d.id')
                        ->get([
                            'd.office_id',
                            'o.office_name',
                            'd.copies',
                        ])
                        ->map(fn ($o) => [
                            'office_id' => $o->office_id,
                            'office_name' => $o->office_name ?? 'Unknown Office',
                            'copies' => $o->copies ?? 1,
                        ])
                        ->values();
                }

                $sourceOffices = DB::table('dcs_masterlist_source_offices as s')
                    ->leftJoin('office as o', 'o.id', '=', 's.office_id')
                    ->where('s.masterlist_id', $latest->id)
                    ->get(['o.office_name']);
                $latestSourceUnit = $sourceOffices->pluck('office_name')->filter()->implode(', ');

                $relatedIds = DB::table('dcs_masterlist_related_docs')
                    ->where('masterlist_id', $latest->id)
                    ->pluck('related_doc_id');
                $latestRelatedDocs = DB::table('dcs_masterlist_registration')
                    ->whereIn('id', $relatedIds)
                    ->get(['id', 'doc_no', 'doc_title'])
                    ->map(fn ($d) => [
                        'masterlist_id' => $d->id,
                        'doc_no' => $d->doc_no,
                        'doc_title' => $d->doc_title,
                    ])
                    ->values();

                return [
                    'exists' => true,
                    'message' => 'Document found.',
                    'next_rev' => $latestRev + 1,
                    'latest_rev' => $latestRev,
                    'latest_scanned_copy_url' => $latest->scanned_masterlist
                        ? Storage::disk('public')->url($latest->scanned_masterlist)
                        : null,
                    'latest_title' => $latest->doc_title,
                    'latest_originator' => $latest->originator_name,
                    'revision_count' => $registrations->count(),
                    'latest_distribution_offices' => $latestDistributionOffices,
                    'latest_source_unit' => $latestSourceUnit,
                    'latest_effectivity_date' => $latest->effectivity_date ? Carbon::parse($latest->effectivity_date)->format('Y-m-d') : null,
                    'latest_no_pages' => $latest->no_pages,
                    'latest_deadline' => $latest->deadline ? Carbon::parse($latest->deadline)->format('Y-m-d') : null,
                    'latest_brief_purpose' => $latest->brief_purpose,
                    'latest_related_documents' => $latestRelatedDocs,
                ];
            }
        }

        if (($result['reason'] ?? '') === 'not_registered') {
            $hasSubType = $subTypeId && (int) $subTypeId > 0;
            $message = 'This document number is not registered';
            if ($hasSubType) {
                $subType = RegisterPersistHelper::dcsDocType($subTypeId);
                $message .= ' under "' . ($subType ? $subType->doc_type_name : 'this sub-type') . '"';
            } elseif ($docTypeId) {
                $type = RegisterPersistHelper::dcsDocType($docTypeId);
                $message .= ' under "' . ($type ? $type->doc_type_name : 'this document type') . '"';
            }
            $message .= '. Please register it as a New Document first.';

            return ['exists' => false, 'message' => $message, 'next_rev' => null];
        }

        $existingDr = $result['existing_dr'] ?? null;
        if (($result['reason'] ?? '') === 'wrong_subtype') {
            $existingSubType = RegisterPersistHelper::dcsDocType($existingDr->sub_type_id ?? null);

            return [
                'exists' => false,
                'wrong_subtype' => true,
                'existing_subtype_name' => $existingSubType ? $existingSubType->doc_type_name : 'Unknown',
                'message' => RegisterPersistHelper::mismatchErrorMessageFromRow($docNo, $result),
                'next_rev' => null,
            ];
        }

        $existingType = RegisterPersistHelper::dcsDocType($existingDr->doc_type_id ?? null);

        return [
            'exists' => false,
            'wrong_type' => true,
            'existing_type_name' => $existingType ? $existingType->doc_type_name : 'Unknown',
            'message' => RegisterPersistHelper::mismatchErrorMessageFromRow($docNo, $result),
            'next_rev' => null,
        ];
    }

    public static function editPayload(int $id): array
    {
        $docRequest = self::findDocumentRequest($id);
        abort_unless($docRequest, 404);

        $ml = DB::table('dcs_masterlist_registration')->where('request_id', $id)->first();
        // Obsolete revisions are editable; Update list groups them under the latest tip.

        $drf = DB::table('dcs_document_request_form')->where('request_id', $id)->first();
        $drfOffices = $drf
            ? DB::table('dcs_drf_offices as d')
                ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
                ->where('d.document_request_form_id', $drf->id)
                ->get(['d.office_id', 'o.office_name'])
            : collect();

        $dcn = DB::table('dcs_document_change_notice')->where('request_id', $id)->first();
        $dcnOfficeName = null;
        if ($dcn && $dcn->office_id) {
            $dcnOfficeName = DB::table('office')->where('id', $dcn->office_id)->value('office_name');
        }
        $dcnOffices = $dcn
            ? DB::table('dcs_dcn_offices as d')
                ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
                ->where('d.dcn_id', $dcn->id)
                ->get(['d.office_id', 'o.office_name'])
            : collect();
        $revisions = $dcn
            ? DB::table('dcs_doc_revision')->where('dcn_id', $dcn->id)->get()
            : collect();

        $retrieval = DB::table('dcs_document_retrieval')->where('request_id', $id)->first();
        $retrievalOfficeColumns = ['r.office_id', 'r.copies', 'o.office_name'];
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_status')) {
            $retrievalOfficeColumns[] = 'r.retrieval_status';
        }
        if (Schema::hasColumn('dcs_retrieval_offices', 'retrieval_date')) {
            $retrievalOfficeColumns[] = 'r.retrieval_date';
        }
        $retrievalOffices = $retrieval
            ? DB::table('dcs_retrieval_offices as r')
                ->leftJoin('office as o', 'o.id', '=', 'r.office_id')
                ->where('r.retrieval_id', $retrieval->id)
                ->get($retrievalOfficeColumns)
            : collect();

        $distribution = DB::table('dcs_document_distribution')->where('request_id', $id)->first();
        $distributionOfficeColumns = ['d.office_id', 'd.copies', 'o.office_name'];
        if (Schema::hasColumn('dcs_distribution_offices', 'distribution_date')) {
            $distributionOfficeColumns[] = 'd.distribution_date';
        }
        $distributionOffices = $distribution
            ? DB::table('dcs_distribution_offices as d')
                ->leftJoin('office as o', 'o.id', '=', 'd.office_id')
                ->where('d.distribution_id', $distribution->id)
                ->orderBy('d.sort_order')
                ->orderBy('d.id')
                ->get($distributionOfficeColumns)
            : collect();

        $approval = DB::table('dcs_approval_records')->where('request_id', $id)->first();

        $sourceOffices = collect();
        if ($ml) {
            $sourceOffices = DB::table('dcs_masterlist_source_offices as s')
                ->leftJoin('office as o', 'o.id', '=', 's.office_id')
                ->where('s.masterlist_id', $ml->id)
                ->get(['s.office_id', 'o.office_name']);
        }

        $syllabi = DB::table('dcs_syllabi as s')
            ->leftJoin('dcs_program_courses as pc', 'pc.id', '=', 's.course_id')
            ->where('s.request_id', $id)
            ->orderBy('s.id')
            ->get([
                's.*',
                'pc.course_name',
                'pc.course_code',
            ]);

        $syllabiGroupsSeed = $syllabi->map(function ($syl) {
            $drfs = DB::table('dcs_syllabi_drf')->where('syllabi_id', $syl->id)->orderBy('id')->get();
            $copies = max(1, (int) $syl->no_copies);
            $rows = [];
            if ($copies === 1) {
                $firstDrf = $drfs->first();
                $rows[] = [
                    'faculty' => $drfs->pluck('faculty_name')->filter()->implode(', '),
                    'date_received' => self::formatDate($syl->date_received),
                    'time_received' => self::formatTime($syl->time_received),
                    'drf_available' => self::pgBool($firstDrf->is_drf_available ?? false),
                    'drf_no' => $firstDrf->drf_no ?? null,
                    'drf_date' => self::formatDate($firstDrf->drf_date ?? null),
                    'drf_received_date' => self::formatDate($firstDrf->drf_received_date ?? null),
                    'scanned_drf' => $firstDrf->scanned_drf ?? null,
                    'scanned_drf_name' => !empty($firstDrf->scanned_drf ?? null) ? basename($firstDrf->scanned_drf) : null,
                ];
            } else {
                foreach ($drfs->take($copies) as $drf) {
                    $rows[] = [
                        'faculty' => $drf->faculty_name,
                        'date_received' => self::formatDate($syl->date_received),
                        'time_received' => self::formatTime($syl->time_received),
                        'drf_available' => self::pgBool($drf->is_drf_available),
                        'drf_no' => $drf->drf_no,
                        'drf_date' => self::formatDate($drf->drf_date),
                        'drf_received_date' => self::formatDate($drf->drf_received_date),
                        'scanned_drf' => $drf->scanned_drf,
                        'scanned_drf_name' => $drf->scanned_drf ? basename($drf->scanned_drf) : null,
                    ];
                }
            }

            return [
                'course_name' => $syl->course_name ?? '',
                'course_code' => $syl->course_code ?? '',
                'availability' => self::pgBool($syl->is_available),
                'no_pages' => $syl->no_pages,
                'copies' => $copies,
                'college_id' => $syl->college_id,
                'program_id' => $syl->program_id,
                'semester_id' => $syl->semester_id,
                'school_year_id' => $syl->school_year_id,
                'rows' => $rows,
            ];
        })->values();

        $dcnOfficesSeed = $dcnOffices->map(fn ($o) => [
            'id' => $o->office_id,
            'label' => $o->office_name ?? 'Unknown',
        ])->values();
        if ($dcnOfficesSeed->isEmpty() && $dcn && $dcn->office_id) {
            $dcnOfficesSeed = collect([[
                'id' => $dcn->office_id,
                'label' => $dcnOfficeName ?? 'Unknown',
            ]]);
        }

        $relatedDocsData = collect();
        if ($ml) {
            $relatedIds = DB::table('dcs_masterlist_related_docs')
                ->where('masterlist_id', $ml->id)
                ->pluck('related_doc_id');
            $relatedDocsData = DB::table('dcs_masterlist_registration')
                ->whereIn('id', $relatedIds)
                ->get(['id', 'doc_no', 'doc_title'])
                ->map(fn ($m) => [
                    'masterlist_id' => $m->id,
                    'doc_no' => $m->doc_no,
                    'doc_title' => $m->doc_title,
                    'label' => $m->doc_title . ($m->doc_no ? ' (' . $m->doc_no . ')' : ''),
                ]);
        }

        return [
            'blocked' => false,
            'docRequest' => $docRequest,
            'drf' => $drf,
            'dcn' => $dcn,
            'revisions' => $revisions,
            'masterlist' => $ml,
            'retrieval' => $retrieval,
            'retrievalOffices' => $retrievalOffices,
            'distribution' => $distribution,
            'distributionOffices' => $distributionOffices,
            'approval' => $approval,
            'drfOfficesSeed' => $drfOffices->map(fn ($o) => [
                'id' => $o->office_id,
                'label' => $o->office_name ?? 'Unknown',
            ])->values(),
            'dcnOfficesSeed' => $dcnOfficesSeed,
            'masterlistSourceSeed' => $sourceOffices->map(fn ($o) => [
                'type' => 'office',
                'id' => $o->office_id,
                'label' => $o->office_name ?? 'Unknown',
            ])->filter(fn ($o) => $o['id'])->values(),
            'masterlistOriginatorSeed' => ($ml && $ml->originator_name)
                ? [['label' => $ml->originator_name]]
                : [],
            'syllabiGroupsSeed' => $syllabiGroupsSeed,
            'syllabiContextSeed' => $syllabi->first(),
            'isSyllabiLikeEdit' => RegisterPersistHelper::isSyllabiLikeSubTypeRow(
                RegisterPersistHelper::dcsDocType($docRequest->sub_type_id)
            ),
            'relatedDocsData' => $relatedDocsData,
        ];
    }

    public static function hydrateRequests(Collection $docs): Collection
    {
        if ($docs->isEmpty()) {
            return $docs;
        }

        $ids = $docs->pluck('id')->all();
        $typeIds = $docs->pluck('doc_type_id')->merge($docs->pluck('sub_type_id'))->filter()->unique()->all();
        $types = $typeIds
            ? DB::table('dcs_doc_types')->whereIn('id', $typeIds)->get()->keyBy('id')
            : collect();

        $mls = DB::table('dcs_masterlist_registration')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $mlIds = $mls->pluck('id')->all();

        $sourceByMl = collect();
        $relatedByMl = collect();
        if ($mlIds) {
            $sourceByMl = DB::table('dcs_masterlist_source_offices as so')
                ->leftJoin('office as o', 'o.id', '=', 'so.office_id')
                ->whereIn('so.masterlist_id', $mlIds)
                ->get(['so.masterlist_id', 'so.office_id', 'o.office_name'])
                ->groupBy('masterlist_id')
                ->map(fn ($rows) => $rows->map(function ($row) {
                    $row->office = (object) ['office_name' => $row->office_name];

                    return $row;
                }));

            $relatedA = DB::table('dcs_masterlist_related_docs as rd')
                ->join('dcs_masterlist_registration as rel', 'rel.id', '=', 'rd.related_doc_id')
                ->whereIn('rd.masterlist_id', $mlIds)
                ->get(['rd.masterlist_id', 'rel.doc_no', 'rel.doc_title']);
            $relatedB = DB::table('dcs_masterlist_related_docs as rd')
                ->join('dcs_masterlist_registration as rel', 'rel.id', '=', 'rd.masterlist_id')
                ->whereIn('rd.related_doc_id', $mlIds)
                ->get(['rd.related_doc_id as masterlist_id', 'rel.doc_no', 'rel.doc_title']);
            $relatedByMl = $relatedA->concat($relatedB)->groupBy('masterlist_id');
        }

        $drfs = DB::table('dcs_document_request_form')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $dcns = DB::table('dcs_document_change_notice')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $dcnIds = $dcns->pluck('id')->all();
        $revsByDcn = $dcnIds
            ? DB::table('dcs_doc_revision')->whereIn('dcn_id', $dcnIds)->orderBy('id')->get()->groupBy('dcn_id')
            : collect();

        $dists = DB::table('dcs_document_distribution')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $distIds = $dists->pluck('id')->all();
        $distOffices = $distIds
            ? DB::table('dcs_distribution_offices as dof')
                ->leftJoin('office as o', 'o.id', '=', 'dof.office_id')
                ->whereIn('dof.distribution_id', $distIds)
                ->orderBy('dof.sort_order')
                ->orderBy('dof.id')
                ->get(['dof.distribution_id', 'dof.office_id', 'dof.copies', 'o.office_name'])
                ->groupBy('distribution_id')
                ->map(fn ($rows) => $rows->map(function ($row) {
                    $row->office = (object) ['office_name' => $row->office_name];

                    return $row;
                }))
            : collect();

        $rets = DB::table('dcs_document_retrieval')->whereIn('request_id', $ids)->get()->keyBy('request_id');
        $retIds = $rets->pluck('id')->all();
        $retOffices = $retIds
            ? DB::table('dcs_retrieval_offices as rof')
                ->leftJoin('office as o', 'o.id', '=', 'rof.office_id')
                ->whereIn('rof.retrieval_id', $retIds)
                ->get(['rof.retrieval_id', 'rof.office_id', 'rof.copies', 'o.office_name'])
                ->groupBy('retrieval_id')
                ->map(fn ($rows) => $rows->map(function ($row) {
                    $row->office = (object) ['office_name' => $row->office_name];

                    return $row;
                }))
            : collect();

        $approvals = DB::table('dcs_approval_records')->whereIn('request_id', $ids)->get()->groupBy('request_id');
        $stamps = DB::table('dcs_document_stamps')->whereIn('document_request_id', $ids)->get()->groupBy('document_request_id');

        $syllabi = DB::table('dcs_syllabi as s')
            ->leftJoin('dcs_program_courses as c', 'c.id', '=', 's.course_id')
            ->whereIn('s.request_id', $ids)
            ->get(['s.*', 'c.course_name', 'c.course_code']);
        $sylIds = $syllabi->pluck('id')->all();
        $drfsBySyl = $sylIds
            ? DB::table('dcs_syllabi_drf')->whereIn('syllabi_id', $sylIds)->get()->groupBy('syllabi_id')
            : collect();
        $syllabiByReq = $syllabi->groupBy('request_id')->map(fn ($rows) => $rows->map(function ($row) use ($drfsBySyl) {
            $row->course = (object) [
                'course_name' => $row->course_name,
                'course_code' => $row->course_code ?? null,
            ];
            $row->drfs = $drfsBySyl->get($row->id, collect());

            return $row;
        }));

        foreach ($docs as $doc) {
            $doc->docType = $types->get($doc->doc_type_id);
            $doc->subType = $types->get($doc->sub_type_id);
            $ml = $mls->get($doc->id);
            if ($ml) {
                $ml->sourceOffices = $sourceByMl->get($ml->id, collect());
                $ml->relatedList = $relatedByMl->get($ml->id, collect())->unique(fn ($r) => $r->doc_no . '|' . $r->doc_title)->values();
            }
            $doc->masterlistRegistration = $ml;
            $doc->documentRequestForm = $drfs->get($doc->id);
            $dcn = $dcns->get($doc->id);
            if ($dcn) {
                $dcn->revisions = $revsByDcn->get($dcn->id, collect());
            }
            $doc->documentChangeNotice = $dcn;
            $dist = $dists->get($doc->id);
            if ($dist) {
                $dist->offices = $distOffices->get($dist->id, collect());
            }
            $doc->documentDistribution = $dist;
            $ret = $rets->get($doc->id);
            if ($ret) {
                $ret->offices = $retOffices->get($ret->id, collect());
            }
            $doc->documentRetrieval = $ret;
            $doc->approvalRecords = $approvals->get($doc->id, collect());
            $doc->stamps = $stamps->get($doc->id, collect());
            $doc->syllabi = $syllabiByReq->get($doc->id, collect());
        }

        return $docs;
    }

    public static function hydrateMasterlists(Collection $records): Collection
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $requestIds = $records->pluck('request_id')->filter()->unique()->all();
        $requests = $requestIds
            ? self::hydrateRequests(DB::table('dcs_document_requests')->whereIn('id', $requestIds)->get())
                ->keyBy(fn ($row) => (int) $row->id)
            : collect();
        $typeIds = $records->pluck('doc_type_id')->filter()->unique()->all();
        $types = $typeIds
            ? DB::table('dcs_doc_types')->whereIn('id', $typeIds)->get()->keyBy('id')
            : collect();
        $mlIds = $records->pluck('id')->all();
        $sourceByMl = DB::table('dcs_masterlist_source_offices as so')
            ->leftJoin('office as o', 'o.id', '=', 'so.office_id')
            ->whereIn('so.masterlist_id', $mlIds)
            ->get(['so.masterlist_id', 'so.office_id', 'o.office_name'])
            ->groupBy('masterlist_id')
            ->map(fn ($rows) => $rows->map(function ($row) {
                $row->office = (object) ['office_name' => $row->office_name];

                return $row;
            }));

        foreach ($records as $ml) {
            $ml->request = $requests->get((int) $ml->request_id);
            $ml->docType = $types->get($ml->doc_type_id);
            $ml->sourceOffices = $sourceByMl->get($ml->id, collect());
        }

        return $records;
    }

    /** JSON-shaped catalog matching the Register Alpine/UI keys. */
    public static function jsCatalog(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $docTypes = DB::table('dcs_doc_types')
            ->orderBy('id')
            ->get(['id', 'parent_id', 'doc_type_name'])
            ->map(fn ($d) => [
                'doc_type_id' => $d->id,
                'parent_id' => $d->parent_id,
                'doc_type_name' => $d->doc_type_name,
                'is_syllabi_like' => self::isSyllabiLikeName($d->doc_type_name),
            ])
            ->values()
            ->all();

        $checklistsByVersion = [];
        $rows = DB::table('dcs_checklist_version as cv')
            ->join('dcs_checklist_types as ct', 'ct.id', '=', 'cv.checklist_id')
            ->orderBy('ct.id')
            ->get([
                'cv.version_id',
                'ct.id as checklist_id',
                'ct.checklist_name',
            ]);

        foreach ($rows as $row) {
            $checklistsByVersion[(string) $row->version_id][] = [
                'checklist_id' => $row->checklist_id,
                'checklist_name' => $row->checklist_name,
            ];
        }

        $programsByCollege = [];
        foreach (DB::table('dcs_programs')->orderBy('program_name')->get(['id', 'college_id', 'program_name', 'program_code']) as $p) {
            $programsByCollege[(string) $p->college_id][] = [
                'program_id' => $p->id,
                'program_name' => $p->program_name,
                'program_code' => $p->program_code,
            ];
        }

        $facultiesByCourse = collect();
        if (Schema::hasTable('dcs_program_course_faculties')) {
            $facultiesByCourse = DB::table('dcs_program_course_faculties as pcf')
                ->join('dcs_faculties as f', 'f.id', '=', 'pcf.faculty_id')
                ->orderBy('f.faculty_name')
                ->get(['pcf.program_course_id', 'f.id', 'f.faculty_name'])
                ->groupBy('program_course_id');
        }

        $courseColumns = ['id', 'program_id', 'semester_id', 'course_name'];
        if (Schema::hasColumn('dcs_program_courses', 'course_code')) {
            $courseColumns[] = 'course_code';
        }

        $coursesByProgramSemester = [];
        foreach (DB::table('dcs_program_courses')->orderBy('course_name')->get($courseColumns) as $c) {
            $coursesByProgramSemester[$c->program_id . ':' . $c->semester_id][] = [
                'id' => $c->id,
                'course_name' => $c->course_name,
                'course_code' => $c->course_code ?? '',
                'faculties' => collect($facultiesByCourse->get($c->id) ?? $facultiesByCourse->get((string) $c->id) ?? [])->map(fn ($f) => [
                    'id' => $f->id,
                    'faculty_name' => $f->faculty_name,
                ])->values()->all(),
            ];
        }

        return $cache = [
            'offices' => DB::table('office')
                ->where('is_active', true)
                ->orderBy('office_name')
                ->get(['id', 'office_name', 'office_code', 'cluster'])
                ->map(fn ($o) => [
                    'office_id' => $o->id,
                    'office_name' => $o->office_name,
                    'office_code' => $o->office_code,
                    'cluster' => $o->cluster,
                ])
                ->values()
                ->all(),
            'clusters' => DB::table('cluster')
                ->where('is_active', true)
                ->orderBy('cluster_name')
                ->get(['id', 'cluster_name', 'cluster_code'])
                ->map(fn ($c) => [
                    'cluster_id' => $c->id,
                    'cluster_name' => $c->cluster_name,
                    'cluster_code' => $c->cluster_code,
                ])
                ->values()
                ->all(),
            'docTypes' => $docTypes,
            'versionTypes' => DB::table('dcs_version_type')
                ->orderBy('version_name')
                ->get(['id', 'version_name'])
                ->map(fn ($v) => [
                    'version_id' => $v->id,
                    'version_name' => $v->version_name,
                ])
                ->values()
                ->all(),
            'approvalBodies' => DB::table('dcs_approval_body')
                ->orderBy('approval_name')
                ->get(['id', 'approval_name'])
                ->map(fn ($a) => [
                    'approval_body_id' => $a->id,
                    'approval_name' => $a->approval_name,
                ])
                ->values()
                ->all(),
            'originators' => DB::table('dcs_originators')
                ->orderBy('originator_name')
                ->get(['id', 'originator_name'])
                ->map(fn ($o) => [
                    'originator_id' => $o->id,
                    'originator_name' => $o->originator_name,
                ])
                ->values()
                ->all(),
            'checklistsByVersion' => $checklistsByVersion,
            'colleges' => DB::table('dcs_colleges')
                ->orderBy('college_name')
                ->get(['id', 'college_name'])
                ->map(fn ($c) => [
                    'college_id' => $c->id,
                    'college_name' => $c->college_name,
                ])
                ->values()
                ->all(),
            'semesters' => DB::table('dcs_semesters')
                ->orderBy('id')
                ->get(['id', 'semester_name'])
                ->map(fn ($s) => [
                    'semester_id' => $s->id,
                    'semester_name' => $s->semester_name,
                ])
                ->values()
                ->all(),
            'schoolYears' => DB::table('dcs_school_years')
                ->orderBy('school_year')
                ->get(['id', 'school_year'])
                ->map(fn ($y) => [
                    'school_year_id' => $y->id,
                    'school_year' => $y->school_year,
                ])
                ->values()
                ->all(),
            'programsByCollege' => $programsByCollege,
            'coursesByProgramSemester' => $coursesByProgramSemester,
            'faculties' => DB::table('dcs_faculties')
                ->orderBy('faculty_name')
                ->get(['id', 'faculty_name', 'college_id'])
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'faculty_name' => $f->faculty_name,
                    'college_id' => $f->college_id,
                ])
                ->values()
                ->all(),
        ];
    }
}
