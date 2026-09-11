<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    json_response(false, null, 'A customer is required.', 422);
}

$pdo = db();
$userStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? AND role = "customer"');
$userStmt->execute([$userId]);
$targetUser = $userStmt->fetch();

if (!$targetUser) {
    json_response(false, null, 'Customer not found.', 404);
}

// Only ever the non-sensitive columns - secret_key and bearer_token
// themselves stay customer-only, same as nobody but the customer ever
// sees their own login password. Admin gets status/visibility, not the
// live credential values, so this view can't itself become a way to
// impersonate the customer's API access.
$credStmt = $pdo->prepare(
    'SELECT client_key, (bearer_token IS NOT NULL) AS has_bearer_token, bearer_token_generated_at,
            payout_callback_url, payin_callback_url
     FROM customer_api_credentials WHERE user_id = ?'
);
$credStmt->execute([$userId]);
$creds = $credStmt->fetch();

$ipsStmt = $pdo->prepare(
    "SELECT cwi.id, cwi.ip_address, cwi.status, cwi.created_at, cwi.updated_at, cwi.reviewed_at,
            u.name AS added_by_name, ru.name AS reviewed_by_name
     FROM customer_whitelisted_ips cwi
     LEFT JOIN users u ON u.id = cwi.added_by
     LEFT JOIN users ru ON ru.id = cwi.reviewed_by
     WHERE cwi.user_id = ? ORDER BY (cwi.status = 'pending') DESC, cwi.created_at DESC"
);
$ipsStmt->execute([$userId]);

json_response(true, [
    'user' => $targetUser,
    'has_api_credentials' => (bool) $creds,
    'client_key' => $creds['client_key'] ?? null,
    'has_bearer_token' => (bool) ($creds['has_bearer_token'] ?? false),
    'bearer_token_generated_at' => $creds['bearer_token_generated_at'] ?? null,
    'payout_callback_url' => $creds['payout_callback_url'] ?? null,
    'payin_callback_url' => $creds['payin_callback_url'] ?? null,
    'whitelisted_ips' => $ipsStmt->fetchAll(),
], 'ok');
