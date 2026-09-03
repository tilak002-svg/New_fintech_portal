<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/gateway_secrets.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

// Providers whose live integration needs a public-safe identifier
// alongside the secret (Razorpay's Key ID, Cashfree's Client ID).
$providersNeedingPublicKey = ['razorpay' => 'Key ID', 'cashfree' => 'Client ID'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = (int) ($input['id'] ?? 0);
$apiKey = trim((string) ($input['api_key'] ?? ''));
$publicKey = trim((string) ($input['public_key'] ?? ''));
$payoutAccountNumber = trim((string) ($input['payout_account_number'] ?? ''));
$sandboxModeProvided = array_key_exists('sandbox_mode', $input);
$sandboxMode = $sandboxModeProvided && (bool) $input['sandbox_mode'];

if ($id <= 0) {
    json_response(false, null, 'A gateway is required.', 422);
}
if (mb_strlen($apiKey) < 8) {
    json_response(false, null, 'Enter an API key of at least 8 characters.', 422);
}
if (mb_strlen($publicKey) > 190) {
    json_response(false, null, 'The public identifier is too long.', 422);
}
if (mb_strlen($payoutAccountNumber) > 40) {
    json_response(false, null, 'The payout account number is too long.', 422);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, display_name, provider, public_key FROM payment_gateways WHERE id = ?');
$stmt->execute([$id]);
$gateway = $stmt->fetch();

if (!$gateway) {
    json_response(false, null, 'Gateway not found.', 404);
}
// Providers that need one issue it and the secret as a pair — rotating
// one without the other would leave the two mismatched.
if (isset($providersNeedingPublicKey[$gateway['provider']]) && $publicKey === '' && !$gateway['public_key']) {
    $label = $providersNeedingPublicKey[$gateway['provider']];
    json_response(false, null, "This provider also needs its {$label} (the public identifier from the same API Keys screen as the secret).", 422);
}

$last4 = mb_substr($apiKey, -4);
$hash = password_hash($apiKey, PASSWORD_DEFAULT);

try {
    $encrypted = gateway_encrypt_secret($apiKey);
} catch (Throwable $e) {
    error_log('[gateways/rotate-key] ' . $e->getMessage());
    json_response(false, null, 'Gateway encryption is not configured on this server. Set GATEWAY_ENCRYPTION_KEY and try again.', 500);
}

$pdo->prepare(
    'UPDATE payment_gateways
     SET api_key_last4 = ?, api_key_hash = ?, api_key_encrypted = ?,
         public_key = COALESCE(NULLIF(?, ""), public_key),
         payout_account_number = COALESCE(NULLIF(?, ""), payout_account_number),
         sandbox_mode = CASE WHEN ? THEN ? ELSE sandbox_mode END
     WHERE id = ?'
)->execute([$last4, $hash, $encrypted, $publicKey, $payoutAccountNumber, $sandboxModeProvided ? 1 : 0, $sandboxMode ? 1 : 0, $id]);

write_audit_log((int) $actor['id'], 'gateway_key_rotated', 'payment_gateway', $id, []);

json_response(true, ['api_key_last4' => $last4], "{$gateway['display_name']}'s key has been rotated.");
