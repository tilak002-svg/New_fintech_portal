<?php
/**
 * Public, unauthenticated, token-gated by ?session= — this is what lets
 * pages/pay-checkout.php poll for completion on behalf of a merchant's
 * end-customer, who has no Verapay account and nothing to authenticate
 * with. The session_token itself is the credential (unguessable, same
 * trust model as a webhook URL) — see database's payment_sessions table.
 * Returns only a bare status, never any gateway/order detail.
 */

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$sessionToken = trim((string) ($_GET['session'] ?? ''));
if ($sessionToken === '' || !preg_match('/^[0-9a-f]{64}$/', $sessionToken)) {
    json_response(false, null, 'Invalid session.', 404);
}

$stmt = db()->prepare(
    'SELECT t.status FROM payment_sessions ps
     JOIN transactions t ON t.id = ps.transaction_id
     WHERE ps.session_token = ?'
);
$stmt->execute([$sessionToken]);
$status = $stmt->fetchColumn();

if ($status === false) {
    json_response(false, null, 'Invalid session.', 404);
}

json_response(true, ['status' => $status], 'ok');
