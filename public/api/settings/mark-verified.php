<?php
/**
 * Records that the customer just ran a successful check on
 * pages/key-verification.php. Purely informational UI state ("Last
 * verified: ...") — not itself a security check, so it's safe to trust
 * the client's report that its own preceding checks passed.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$pdo = db();
$stmt = $pdo->prepare('UPDATE customer_api_credentials SET last_verified_at = NOW() WHERE user_id = ?');
$stmt->execute([$user['id']]);

if ($stmt->rowCount() === 0) {
    json_response(false, null, 'Generate your API credentials first.', 422);
}

json_response(true, ['last_verified_at' => gmdate('Y-m-d H:i:s')], 'Verification recorded.');
