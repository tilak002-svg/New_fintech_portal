<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$ip = trim((string) ($input['ip_address'] ?? ''));

if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
    json_response(false, null, 'Enter a valid IPv4 or IPv6 address.', 422);
}

// Separate from the per-(user, ip) uniqueness check below - this one
// catches a customer spamming requests for many DIFFERENT ips, not just
// repeats of the same one.
enforce_rate_limit("api_ip_request:user:{$user['id']}", 5, 3600, 'Too many IP whitelist requests. Please wait before submitting another.');

$pdo = db();
$stmt = $pdo->prepare('SELECT id, status FROM customer_whitelisted_ips WHERE user_id = ? AND ip_address = ?');
$stmt->execute([$user['id'], $ip]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['status'] === 'approved') {
        json_response(false, null, 'That IP is already whitelisted for your account.', 422);
    }
    if ($existing['status'] === 'pending') {
        json_response(false, null, 'You already have a pending request for that IP.', 422);
    }
    // status === 'rejected' - resubmission flips the same row back to
    // pending rather than inserting a second row, which the UNIQUE
    // (user_id, ip_address) key would reject anyway.
    $pdo->prepare("UPDATE customer_whitelisted_ips SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL WHERE id = ?")
        ->execute([$existing['id']]);
} else {
    $pdo->prepare("INSERT INTO customer_whitelisted_ips (user_id, ip_address, status) VALUES (?, ?, 'pending')")
        ->execute([$user['id'], $ip]);
}

write_audit_log((int) $user['id'], 'customer_api_ip_requested', 'user', (int) $user['id'], ['ip_address' => $ip]);

notify_admins(
    $pdo,
    'api_ip',
    'New IP whitelist request',
    "{$user['name']} requested to whitelist {$ip} for API access."
);

json_response(true, ['status' => 'pending'], 'Your request has been submitted for review.', 201);
