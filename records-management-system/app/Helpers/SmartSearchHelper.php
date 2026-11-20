<?php

namespace App\Helpers;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SmartSearchHelper — Intelligent tokenized search filtering and relevance ranking for RMS.
 *
 * Provides:
 *  1. Multi-token & phrase parsing with alphanumeric, digit, and word support.
 *  2. Smart filtering: matches full phrases or token-level AND conditions across fields.
 *  3. Best-fit relevance ranking: ranks exact matches, prefix matches, and multi-token
 *     densities highest, ensuring the most fit result appears at the top.
 */
class SmartSearchHelper
{
    /**
     * Splits query into distinct alphanumeric tokens, preserving digits and single characters.
     * e.g., "NAP Form 1" -> ["nap", "form", "1"]
     *       "DTS-2026-0012" -> ["dts", "2026", "0012"]
     */
    public static function tokenize(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($search)) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $tokens[$part] = $part;
            }
        }

        return array_values($tokens);
    }

    /**
     * Normalizes search query, resolving optional base64 strings (e.g. from QR scanners).
     */
    public static function normalize(string $search): string
    {
        $val = trim($search);
        if ($val === '') {
            return '';
        }

        $decoded = base64_decode($val, true);
        if ($decoded !== false && ctype_print($decoded) && preg_match('/^[A-Za-z0-9_\-\.\:\/ ]+$/', $decoded)) {
            $val = trim($decoded);
        }

        return $val;
    }

    /**
     * Calculates an in-memory relevance score for a target (either a single text string
     * or a structured associative array with 'primary', 'secondary', and 'meta' keys).
     *
     * Higher score = better fit on search.
     */
    public static function calculateScore(string $search, string|array $target): int
    {
        $query = mb_strtolower(trim($search));
        if ($query === '') {
            return 0;
        }

        if (is_string($target)) {
            $primary = mb_strtolower(trim($target));
            $secondary = '';
            $meta = '';
        } else {
            $primary = mb_strtolower(trim((string) ($target['primary'] ?? $target['code'] ?? $target['doc_no'] ?? $target['control_number'] ?? '')));
            $secondary = mb_strtolower(trim((string) ($target['secondary'] ?? $target['title'] ?? $target['subject'] ?? '')));
            $meta = mb_strtolower(trim((string) ($target['meta'] ?? $target['other'] ?? $target['keywords'] ?? '')));
        }

        $allHaystack = trim($primary . ' ' . $secondary . ' ' . $meta);
        if ($allHaystack === '') {
            return 0;
        }

        $score = 0;

        // 1. Exact matches (Highest priority)
        if ($primary !== '' && $primary === $query) {
            $score += 10000;
        } elseif ($secondary !== '' && $secondary === $query) {
            $score += 5000;
        }

        // 2. Prefix matches (Starts with search string)
        if ($primary !== '' && str_starts_with($primary, $query)) {
            $score += 3000;
        } elseif ($secondary !== '' && str_starts_with($secondary, $query)) {
            $score += 2000;
        }

        // 3. Contains full search phrase
        if ($primary !== '' && str_contains($primary, $query)) {
            $score += 1500;
        }
        if ($secondary !== '' && str_contains($secondary, $query)) {
            $score += 1000;
        }
        if ($meta !== '' && str_contains($meta, $query)) {
            $score += 500;
        }

        // 4. Token-level matching & proximity
        $tokens = self::tokenize($query);
        if (!empty($tokens)) {
            $matchedTokensCount = 0;
            $positions = [];
            $ordered = true;
            $lastPos = -1;

            foreach ($tokens as $token) {
                $pos = mb_strpos($allHaystack, $token);
                if ($pos !== false) {
                    $matchedTokensCount++;
                    $positions[] = $pos;
                    if ($lastPos !== -1 && $pos < $lastPos) {
                        $ordered = false;
                    }
                    $lastPos = $pos;

                    // Field-specific token bonuses
                    if ($primary !== '' && str_contains($primary, $token)) {
                        $score += 200;
                    }
                    if ($secondary !== '' && str_contains($secondary, $token)) {
                        $score += 100;
                    }
                    if ($meta !== '' && str_contains($meta, $token)) {
                        $score += 40;
                    }
                }
            }

            // All tokens present bonus
            if ($matchedTokensCount === count($tokens)) {
                $score += 400;

                // Tokens in order bonus
                if ($ordered && count($positions) > 1) {
                    $score += 150;
                }

                // Proximity / compactness bonus
                if (!empty($positions)) {
                    $span = max($positions) - min($positions);
                    $score += max(0, 150 - (int) $span);
                }
            }
        }

        return $score;
    }

    /**
     * Applies smart filtering and relevance ordering directly to an Eloquent/Query Builder instance.
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param string $search
     * @param array $columnsConfig Array of columns with roles ['primary' => [...], 'secondary' => [...], 'meta' => [...]] or flat list of columns
     * @return void
     */
    public static function applySearch($query, string $search, array $columnsConfig): void
    {
        $search = self::normalize($search);
        if ($search === '') {
            return;
        }

        $tokens = self::tokenize($search);
        if (empty($tokens) && $search === '') {
            return;
        }

        // Normalize columns structure
        if (isset($columnsConfig['primary']) || isset($columnsConfig['secondary']) || isset($columnsConfig['meta'])) {
            $primaryCols   = (array) ($columnsConfig['primary'] ?? []);
            $secondaryCols = (array) ($columnsConfig['secondary'] ?? []);
            $metaCols      = (array) ($columnsConfig['meta'] ?? []);
        } else {
            // Flat list: treat 1st as primary, 2nd as secondary, remaining as meta
            $flat = array_values($columnsConfig);
            $primaryCols   = isset($flat[0]) ? [$flat[0]] : [];
            $secondaryCols = isset($flat[1]) ? [$flat[1]] : [];
            $metaCols      = array_slice($flat, 2);
        }

        // Defensively filter columns to only those belonging to tables or aliases actually present in the query
        $primaryCols   = self::filterJoinedColumns($query, $primaryCols);
        $secondaryCols = self::filterJoinedColumns($query, $secondaryCols);
        $metaCols      = self::filterJoinedColumns($query, $metaCols);

        $allCols = array_values(array_unique(array_merge($primaryCols, $secondaryCols, $metaCols)));
        if (empty($allCols)) {
            return;
        }

        $phrase = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        $prefix = str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
        $searchLower = mb_strtolower($search);

        // --- 1. FILTERING (WHERE CLAUSE) ---
        // Record matches if:
        // - Any column contains the entire search phrase, OR
        // - (If multiple tokens) every token matches at least one column
        $query->where(function ($q) use ($allCols, $phrase, $tokens) {
            // Phrase match across any column
            $q->where(function ($sub) use ($allCols, $phrase) {
                foreach ($allCols as $idx => $col) {
                    $method = $idx === 0 ? 'where' : 'orWhere';
                    $colExpr = (stripos($col, '(') !== false) ? DB::raw($col) : $col;
                    $sub->{$method}($colExpr, 'ILIKE', $phrase);
                }
            });

            // Token-level AND match across any columns
            if (count($tokens) > 1) {
                $q->orWhere(function ($allTokensSub) use ($tokens, $allCols) {
                    foreach ($tokens as $tok) {
                        $likeTok = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $tok) . '%';
                        $allTokensSub->where(function ($oneTokSub) use ($allCols, $likeTok) {
                            foreach ($allCols as $idx => $col) {
                                $method = $idx === 0 ? 'where' : 'orWhere';
                                $colExpr = (stripos($col, '(') !== false) ? DB::raw($col) : $col;
                                $oneTokSub->{$method}($colExpr, 'ILIKE', $likeTok);
                            }
                        });
                    }
                });
            }
        });

        // --- 2. RELEVANCE RANKING (ORDER BY CLAUSE) ---
        // Uses CASE WHEN to calculate a relevance score in SQL so the most fit item is at the top.
        $caseClauses = [];
        $bindings = [];

        // Exact match on primary
        foreach ($primaryCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) = ? THEN 10000";
            $bindings[] = $searchLower;
        }

        // Exact match on secondary
        foreach ($secondaryCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) = ? THEN 5000";
            $bindings[] = $searchLower;
        }

        // Prefix match on primary
        foreach ($primaryCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 3000";
            $bindings[] = $prefix;
        }

        // Prefix match on secondary
        foreach ($secondaryCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 2000";
            $bindings[] = $prefix;
        }

        // Contains full phrase on primary
        foreach ($primaryCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 1500";
            $bindings[] = $phrase;
        }

        // Contains full phrase on secondary
        foreach ($secondaryCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 1000";
            $bindings[] = $phrase;
        }

        // Contains full phrase on meta
        foreach ($metaCols as $col) {
            $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 500";
            $bindings[] = $phrase;
        }

        // Token match boosts
        if (count($tokens) > 1) {
            foreach ($tokens as $tok) {
                $tokLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $tok) . '%';
                foreach ($primaryCols as $col) {
                    $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 200";
                    $bindings[] = $tokLike;
                }
                foreach ($secondaryCols as $col) {
                    $caseClauses[] = "WHEN LOWER({$col}) LIKE ? THEN 100";
                    $bindings[] = $tokLike;
                }
            }
        }

        if (!empty($caseClauses)) {
            $orderSql = "CASE " . implode(" ", $caseClauses) . " ELSE 0 END DESC";
            $query->orderByRaw($orderSql, $bindings);
        }
    }

    /**
     * Sorts an in-memory collection of objects or arrays by smart relevance score.
     */
    public static function sortCollection(Collection $collection, string $search, callable $fieldExtractor): Collection
    {
        $search = trim($search);
        if ($search === '' || $collection->isEmpty()) {
            return $collection;
        }

        return $collection->sortByDesc(function ($item) use ($search, $fieldExtractor) {
            $target = $fieldExtractor($item);
            return self::calculateScore($search, $target);
        })->values();
    }

    /**
     * Filter columns to only include those belonging to tables or aliases actually present in the query.
     */
    protected static function filterJoinedColumns($query, array $columns): array
    {
        try {
            $baseQuery = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
            if (!($baseQuery instanceof \Illuminate\Database\Query\Builder)) {
                return $columns;
            }

            $aliases = [];
            $from = $baseQuery->from ?? '';
            if (is_string($from) && $from !== '') {
                if (preg_match('/\s+as\s+(\w+)/i', $from, $m)) {
                    $aliases[strtolower($m[1])] = true;
                } elseif (preg_match('/\s+(\w+)$/i', $from, $m)) {
                    $aliases[strtolower($m[1])] = true;
                } else {
                    $aliases[strtolower($from)] = true;
                }
            }

            if (is_array($baseQuery->joins)) {
                foreach ($baseQuery->joins as $join) {
                    $table = $join->table ?? '';
                    if (is_string($table) && $table !== '') {
                        if (preg_match('/\s+as\s+(\w+)/i', $table, $m)) {
                            $aliases[strtolower($m[1])] = true;
                        } elseif (preg_match('/\s+(\w+)$/i', $table, $m)) {
                            $aliases[strtolower($m[1])] = true;
                        } else {
                            $aliases[strtolower($table)] = true;
                        }
                    }
                }
            }

            if (empty($aliases)) {
                return $columns;
            }

            return array_values(array_filter($columns, function ($col) use ($aliases) {
                if (is_string($col) && str_contains($col, '.')) {
                    $prefix = strtolower(explode('.', $col, 2)[0]);
                    if (str_contains($prefix, '(')) {
                        return true;
                    }
                    return isset($aliases[$prefix]);
                }
                return true;
            }));
        } catch (\Throwable) {
            return $columns;
        }
    }
}
