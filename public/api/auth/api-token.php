<?php
/**
 * Machine-to-machine token exchange: a customer's own external system
 * (which has no browser session and never will) trades client_key +
 * secret_key for a fresh bearer token here. This is how a real
 * integration actually obtains a token — Settings → API access's
 * "Generate token" button is a convenience for copy-pasting one while
 * logged in, not the primary path.
 *
 * Not behind api_guard() - deliberately unauthenticated by session,
 * since the whole point is not needing one. IP whitelist is still
 * enforced, same as every other use of the resulting token.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
enforce_rate_limit("api_token:ip:{$ip}", 30, 300, 'Too many token requests from this network. Please try again shortly.');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$clientKey = trim((string) ($input['client_key'] ?? ''));
$secretKey = (string) ($input['secret_key'] ?? '');

if ($clientKey === '' || $secretKey === '') {
    json_response(false, null, 'client_key and secret_key are required.', 422, 'VALIDATION_ERROR');
}

// Scoped by the client_key itself too — brute-forcing one merchant's
// secret_key from rotating IPs is caught here even though the per-IP
// limit above wouldn't catch it.
enforce_rate_limit("api_token:client:{$clientKey}", 10, 300, 'Too many token requests for this client_key. Please try again shortly.');

$pdo = db();
$stmt = $pdo->prepare('SELECT user_id, secret_key_hash FROM customer_api_credentials WHERE client_key = ?');
$stmt->execute([$clientKey]);
$creds = $stmt->fetch();

if (!$creds || !password_verify($secretKey, $creds['secret_key_hash'])) {
    // Same message either way - never reveal whether the client_key itself was valid.
    json_response(false, null, 'Invalid client_key or secret_key.', 401, 'INVALID_CREDENTIALS');
}

$userId = (int) $creds['user_id'];

$userStmt = $pdo->prepare('SELECT status FROM users WHERE id = ?');
$userStmt->execute([$userId]);
if ($userStmt->fetchColumn() !== 'active') {
    json_response(false, null, 'This account is not active.', 403, 'ACCOUNT_INACTIVE');
}

$ipStmt = $pdo->prepare("SELECT 1 FROM customer_whitelisted_ips WHERE user_id = ? AND ip_address = ? AND status = 'approved'");
$ipStmt->execute([$userId, $ip]);
if (!$ipStmt->fetchColumn()) {
    write_audit_log($userId, 'api_token_exchange_blocked_ip', 'user', $userId, ['ip' => $ip]);
    json_response(false, ['ip' => $ip], 'This request\'s IP address is not whitelisted for this account. Contact support to have it added.', 403, 'IP_NOT_WHITELISTED');
}

$payload = [
    'iss' => 'verapay',
    'sub' => (string) $userId,
    'iat' => time(),
    'exp' => time() + (365 * 24 * 60 * 60),
    'jti' => bin2hex(random_bytes(8)),
];
$token = jwt_encode($payload, PLATFORM_JWT_SECRET);

$pdo->prepare('UPDATE customer_api_credentials SET bearer_token = ?, bearer_token_generated_at = NOW() WHERE user_id = ?')
    ->execute([$token, $userId]);

write_audit_log($userId, 'api_token_exchanged', 'user', $userId, ['ip' => $ip]);

json_response(true, ['bearer_token' => $token, 'expires_at' => date('c', $payload['exp'])], 'Token issued.');
