<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = (int) ($input['id'] ?? 0);

if ($id <= 0) {
    json_response(false, null, 'An IP whitelist entry is required.', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, user_id, ip_address, status FROM customer_whitelisted_ips WHERE id = ?');
$stmt->execute([$id]);
$entry = $stmt->fetch();

if (!$entry) {
    json_response(false, null, 'That whitelist entry no longer exists.', 404);
}
// Revoke only applies to already-approved entries - pending/rejected rows
// are handled by approve-api-ip.php / reject-api-ip.php instead, so a
// stale UI reference (e.g. two admin tabs open) can't hit this path on a
// row that changed status in between.
if ($entry['status'] !== 'approved') {
    json_response(false, null, 'Only approved entries can be revoked.', 422);
}

$pdo->prepare('DELETE FROM customer_whitelisted_ips WHERE id = ?')->execute([$id]);

write_audit_log((int) $actor['id'], 'customer_api_ip_removed', 'user', (int) $entry['user_id'], ['ip_address' => $entry['ip_address']]);

json_response(true, null, "{$entry['ip_address']} has been removed.");
