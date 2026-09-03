<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/gateway_secrets.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$allowedProviders = ['razorpay', 'payu', 'cashfree', 'stripe', 'paypal', 'other'];
// Providers whose live integration needs a public-safe identifier
// alongside the secret (Razorpay's Key ID, Cashfree's Client ID).
$providersNeedingPublicKey = ['razorpay' => 'Key ID', 'cashfree' => 'Client ID'];

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$name = trim((string) ($input['display_name'] ?? ''));
$provider = $input['provider'] ?? '';
$apiKey = trim((string) ($input['api_key'] ?? ''));
$publicKey = trim((string) ($input['public_key'] ?? ''));
$payoutAccountNumber = trim((string) ($input['payout_account_number'] ?? ''));
$sandboxMode = !array_key_exists('sandbox_mode', $input) || (bool) $input['sandbox_mode'];

if ($name === '' || mb_strlen($name) > 80) {
    json_response(false, null, 'Enter a name for this gateway (up to 80 characters).', 422);
}
if (!in_array($provider, $allowedProviders, true)) {
    json_response(false, null, 'Select a valid provider.', 422);
}
if (mb_strlen($apiKey) < 8) {
    json_response(false, null, 'Enter an API key of at least 8 characters.', 422);
}
if (isset($providersNeedingPublicKey[$provider]) && $publicKey === '') {
    $label = $providersNeedingPublicKey[$provider];
    json_response(false, null, "This provider also needs its {$label} (the public identifier from the same API Keys screen as the secret).", 422);
}
if (mb_strlen($publicKey) > 190) {
    json_response(false, null, 'The public identifier is too long.', 422);
}
if (mb_strlen($payoutAccountNumber) > 40) {
    json_response(false, null, 'The payout account number is too long.', 422);
}

$pdo = db();
$last4 = mb_substr($apiKey, -4);
$hash = password_hash($apiKey, PASSWORD_DEFAULT);

try {
    $encrypted = gateway_encrypt_secret($apiKey);
} catch (Throwable $e) {
    error_log('[gateways/create] ' . $e->getMessage());
    json_response(false, null, 'Gateway encryption is not configured on this server. Set GATEWAY_ENCRYPTION_KEY and try again.', 500);
}

$stmt = $pdo->prepare(
    'INSERT INTO payment_gateways (display_name, provider, api_key_last4, api_key_hash, api_key_encrypted, public_key, payout_account_number, sandbox_mode, status, is_default)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, "inactive", 0)'
);
$stmt->execute([$name, $provider, $last4, $hash, $encrypted, $publicKey ?: null, $provider === 'razorpay' ? ($payoutAccountNumber ?: null) : null, $sandboxMode ? 1 : 0]);
$id = (int) $pdo->lastInsertId();

write_audit_log((int) $actor['id'], 'gateway_created', 'payment_gateway', $id, ['provider' => $provider, 'display_name' => $name]);

json_response(true, ['id' => $id], 'Gateway added. Activate it when you are ready to accept traffic through it.', 201);
