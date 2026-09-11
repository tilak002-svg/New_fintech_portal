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
    json_response(false, null, 'That whitelist request no longer exists.', 404);
}
if ($entry['status'] !== 'pending') {
    json_response(false, null, 'This request has already been reviewed.', 422);
}

$pdo->prepare("UPDATE customer_whitelisted_ips SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
    ->execute([$actor['id'], $id]);

$pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, "api_ip", ?, ?)')
    ->execute([$entry['user_id'], 'IP whitelist request rejected', "Your request to whitelist {$entry['ip_address']} was rejected. Contact support for details."]);

write_audit_log((int) $actor['id'], 'customer_api_ip_rejected', 'user', (int) $entry['user_id'], ['ip_address' => $entry['ip_address'], 'whitelist_id' => $id]);

json_response(true, ['status' => 'rejected'], "{$entry['ip_address']} has been rejected.");
