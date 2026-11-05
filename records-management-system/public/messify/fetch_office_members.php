<?php
// =============================================================================
// fetch_office_members.php — Fetch members of an Office Group Chat
// =============================================================================
// GET params:
//   office_id (int, optional) — The office ID to fetch members for
//   conv_id   (string, optional) — e.g. "office_31"
// Requires: Authentication
// =============================================================================

require_once __DIR__ . '/bootstrap.php';

$internalSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$internalAccId  = (int) ($_SERVER['HTTP_X_INTERNAL_ACCOUNT_ID'] ?? 0);

if ($internalSecret !== '' && hash_equals(INTERNAL_PUSH_SECRET, $internalSecret) && $internalAccId > 0) {
    $myAccountId = $internalAccId;
    $myOfficeId  = 0;
    $isAdmin     = Auth::checkIsAdmin($myAccountId);
} else {
    session_start();
    Auth::require();
    $myAccountId = Auth::accountId();
    $myOfficeId  = (int) ($_SESSION['office_id'] ?? 0);
    $isAdmin     = Auth::isAdmin();
    session_write_close();
}

header('Content-Type: application/json');

$officeId = isset($_GET['office_id']) ? (int) $_GET['office_id'] : 0;
$convId   = trim($_GET['conv_id'] ?? '');

if ($officeId <= 0 && $convId !== '') {
    if (str_starts_with($convId, 'office_')) {
        $officeId = OfficeChatManager::parseOfficeId($convId);
    }
}

if ($officeId <= 0) {
    if ($myOfficeId > 0) {
        $officeId = $myOfficeId;
    } else {
        $uInfo = UserResolver::getUserInfo($myAccountId);
        $officeId = (int) ($uInfo['office_id'] ?? 0);
    }
}

if ($officeId <= 0) {
    echo json_encode(['success' => false, 'error' => 'No office specified or assigned.', 'members' => []]);
    exit;
}

// Security: Non-admins can only view their own office group chat members
$actualUserOfficeId = $myOfficeId > 0 ? $myOfficeId : (int) (UserResolver::getUserInfo($myAccountId)['office_id'] ?? 0);
if (!$isAdmin && $actualUserOfficeId !== $officeId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not belong to this office group chat.', 'members' => []]);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Fetch office details
    $stmtOff = $pdo->prepare('SELECT id, office_name, office_code FROM ' . Database::t('office') . ' WHERE id = :id LIMIT 1');
    $stmtOff->execute([':id' => $officeId]);
    $officeRow = $stmtOff->fetch(PDO::FETCH_ASSOC);

    $officeName = $officeRow['office_name'] ?? 'Office Group Chat';
    $officeCode = $officeRow['office_code'] ?? '';

    $tblDetails = Database::t('account_details');
    $tblAccount = Database::t('account');
    $tblRole    = Database::t('condition_key');
    $tblPerm    = Database::t('condition_details');

    $stmt = $pdo->prepare("
        SELECT ad.account_id,
               ad.first_name,
               ad.last_name,
               ad.email,
               ad.office_id,
               ad.is_currently_online,
               ad.last_online_time,
               ad.avatar_url,
               COALESCE(ad.is_chatify_verified, FALSE) AS is_chatify_verified,
               ck.key_name AS role_name,
               cd.is_sadm
        FROM {$tblDetails} ad
        LEFT JOIN {$tblAccount} a ON a.id = ad.account_id
        LEFT JOIN {$tblRole} ck ON ck.id = a.account_role
        LEFT JOIN {$tblPerm} cd ON cd.key_id = ck.modifier_key
        WHERE ad.office_id = :oid
        ORDER BY
          CASE WHEN cd.is_sadm = TRUE OR ad.account_id = 1 THEN 0 ELSE 1 END,
          ad.first_name ASC, ad.last_name ASC
    ");
    $stmt->execute([
        ':oid' => $officeId,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $members = [];
    foreach ($rows as $row) {
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = $row['email'] ?? ('User #' . $row['account_id']);
        }
        $isSadm = (!empty($row['is_sadm']) || (int)$row['account_id'] === 1);

        $members[] = [
            'account_id'          => (int) $row['account_id'],
            'full_name'           => $fullName,
            'first_name'          => $row['first_name'] ?? '',
            'last_name'           => $row['last_name'] ?? '',
            'email'               => $row['email'] ?? '',
            'username'            => $row['email'] ?? '',
            'avatar_url'          => $row['avatar_url'] ?? null,
            'is_currently_online' => (bool) ($row['is_currently_online'] ?? false),
            'last_online_time'    => $row['last_online_time'] ?? null,
            'is_chatify_verified' => (bool) ($row['is_chatify_verified'] ?? false) || $isSadm,
            'is_super_admin'      => $isSadm,
            'role_name'           => $isSadm ? 'Super Admin' : ($row['role_name'] ?? 'Member'),
        ];
    }

    echo json_encode([
        'success'      => true,
        'office'       => [
            'id'   => $officeId,
            'name' => $officeName,
            'code' => $officeCode,
        ],
        'member_count' => count($members),
        'members'      => $members,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'members' => []]);
}
