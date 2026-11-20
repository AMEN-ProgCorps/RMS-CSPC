<?php
// =============================================================================
// search_users_gc.php — Search Users for Custom Group Chat Member Selection
// =============================================================================
// GET params:
//   q (string)        — Required search query (user name or office name/code)
//   group_id (int)    — Optional group ID (to exclude existing members)
//   limit (int)       — Max users to return (default 5)
// Requires: Authentication
// Returns JSON: { users: [{account_id, full_name, avatar_url, office_name, office_code, is_messify_verified}] }
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

session_start();
Auth::require();
session_write_close();

$myAccountId = Auth::accountId();

$q       = trim($_GET['q'] ?? '');
$groupId = (int) ($_GET['group_id'] ?? 0);
$limit   = isset($_GET['limit']) ? min(20, max(1, (int)$_GET['limit'])) : 5;

if ($q === '') {
    echo json_encode(['users' => []]);
    exit;
}

$excludeIds = [$myAccountId => true];

if ($groupId > 0) {
    try {
        $pdo = Database::getConnection();
        $groupMembersTable = Database::t('messify_chat_custom_group_members');
        $stmt = $pdo->prepare("SELECT account_id FROM {$groupMembersTable} WHERE group_id = :gid");
        $stmt->execute([':gid' => $groupId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $excludeIds[(int) $row['account_id']] = true;
        }
    } catch (Throwable $e) {
        error_log('search_users_gc.php exclude members — ' . $e->getMessage());
    }
}

// Search up to 50 users via UserResolver (matches name, email, office_code, office_name)
$result  = UserResolver::searchUsers($q, $myAccountId, 50);
$matched = $result['users'] ?? [];

// Multi-word fallback for combined queries (e.g. "Juan ICTU")
if (count($matched) < $limit && str_contains($q, ' ')) {
    $tokens = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
    if (!empty($tokens)) {
        try {
            $pdo = Database::getConnection();
            $adTable = Database::t('account_details');
            $oTable  = Database::t('office');
            $verCol  = Database::verifiedColumn();

            $tokenClauses = [];
            $tokenParams = [':my_id' => $myAccountId];
            foreach ($tokens as $idx => $tok) {
                $p = ':t_' . $idx;
                $tokenParams[$p] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $tok) . '%';
                $tokenClauses[] = "(
                    ad.first_name ILIKE {$p}
                    OR ad.last_name ILIKE {$p}
                    OR (ad.first_name || ' ' || ad.last_name) ILIKE {$p}
                    OR ad.email ILIKE {$p}
                    OR o.office_name ILIKE {$p}
                    OR o.office_code ILIKE {$p}
                )";
            }
            $stmt = $pdo->prepare(
                "SELECT ad.account_id,
                        ad.first_name,
                        ad.last_name,
                        ad.email,
                        ad.office_id,
                        o.office_name,
                        o.office_code,
                        ad.avatar_url,
                        COALESCE(ad.{$verCol}, FALSE) AS is_messify_verified
                 FROM {$adTable} ad
                 LEFT JOIN {$oTable} o ON o.id = ad.office_id
                 WHERE ad.account_id != :my_id
                   AND (" . implode(' AND ', $tokenClauses) . ")
                 LIMIT 50"
            );
            $stmt->execute($tokenParams);
            $seenIds = array_flip(array_column($matched, 'account_id'));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $aid = (int) $row['account_id'];
                if (!isset($seenIds[$aid])) {
                    $matched[] = [
                        'account_id'          => $aid,
                        'full_name'           => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                        'office_name'         => $row['office_name'] ?? null,
                        'office_code'         => $row['office_code'] ?? null,
                        'avatar_url'          => $row['avatar_url'] ?? null,
                        'is_messify_verified' => $row['is_messify_verified'] ?? false,
                    ];
                    $seenIds[$aid] = true;
                }
            }
        } catch (Throwable $e) {
            error_log('search_users_gc.php token fallback — ' . $e->getMessage());
        }
    }
}

$users = [];
foreach ($matched as $user) {
    $accId = (int) $user['account_id'];
    if (isset($excludeIds[$accId])) {
        continue;
    }
    $users[] = [
        'account_id'          => $accId,
        'full_name'           => $user['full_name'],
        'avatar_url'          => $user['avatar_url'] ?? null,
        'office_name'         => $user['office_name'] ?? '',
        'office_code'         => $user['office_code'] ?? '',
        'is_messify_verified' => UserResolver::parsePgBool($user['is_messify_verified'] ?? false),
    ];
    if (count($users) >= $limit) {
        break;
    }
}

echo json_encode(['users' => $users]);
