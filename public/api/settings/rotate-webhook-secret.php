<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gateway_secrets.php';

$user = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$secret = bin2hex(random_bytes(24));

try {
    $encrypted = gateway_encrypt_secret($secret);
} catch (Throwable $e) {
    error_log('[settings/rotate-webhook-secret] ' . $e->getMessage());
    json_response(false, null, 'Webhook signing is not configured on this server. Contact support.', 500);
}

$pdo = db();
$stmt = $pdo->prepare('UPDATE customer_api_credentials SET webhook_signing_secret_encrypted = ? WHERE user_id = ?');
$stmt->execute([$encrypted, $user['id']]);

if ($stmt->rowCount() === 0) {
    json_response(false, null, 'API credentials have not been provisioned yet. Reload the page and try again.', 409);
}

write_audit_log((int) $user['id'], 'api_webhook_secret_rotated', 'user', (int) $user['id'], []);

// The only moment the plaintext is ever available again — deliveries sign
// with the decrypted value server-side, but it is never displayed after
// this response, matching every other secret in this app.
json_response(true, ['webhook_signing_secret' => $secret], 'Webhook signing secret rotated. Copy it now — it will not be shown again. Update your receiver before the next delivery.');
