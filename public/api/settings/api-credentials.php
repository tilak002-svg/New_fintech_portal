<?php
/**
 * A customer's own API credentials (Settings → API access). Auto-provisions
 * the ENTIRE API access configuration atomically on first load — client
 * key/secret, webhook signing secret, and the PayIn/PayOut callback
 * configuration (initialized to "not configured": this platform's callback
 * URLs are the CUSTOMER's own backend endpoints, which only they can supply
 * — Verapay has no way to know a merchant's website domain, so this never
 * invents one; see includes/customer_webhooks.php for how those URLs are
 * used once the customer sets them via save-api-webhooks.php).
 *
 * Idempotent by construction: provisioning only ever runs once per user
 * (gated on customer_api_credentials having no row yet) and the whole
 * first-time INSERT is one DB transaction — a later GET always reuses the
 * same row, same client_key, same callback config, never regenerating
 * anything. Rotation (rotate-api-secret.php / rotate-webhook-secret.php)
 * and callback URL changes (save-api-webhooks.php) are separate, explicit
 * actions — this endpoint never mutates an existing row.
 *
 * Also returns the IP whitelist admin has approved for this account,
 * read-only here since only admin can change it.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gateway_secrets.php';

$user = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM customer_api_credentials WHERE user_id = ?');
$stmt->execute([$user['id']]);
$creds = $stmt->fetch();

// Only non-null the one time this request is the one that generated it —
// like every other secret in this app, the plaintext is shown exactly
// once and only the hash persists after. A later GET (page refresh, etc.)
// correctly gets secret_key_plaintext: null even though secret_key_masked
// is always populated.
$secretKeyPlaintext = null;
$webhookSecretPlaintext = null;

if (!$creds) {
    $clientKey = 'VP' . strtoupper(bin2hex(random_bytes(5)));
    $secretKeyPlaintext = bin2hex(random_bytes(16));
    $webhookSecretPlaintext = bin2hex(random_bytes(24));

    try {
        $webhookSecretEncrypted = gateway_encrypt_secret($webhookSecretPlaintext);
    } catch (Throwable $e) {
        error_log('[settings/api-credentials] provisioning failed: ' . $e->getMessage());
        json_response(false, null, 'API credential encryption is not configured on this server. Set GATEWAY_ENCRYPTION_KEY and try again.', 500);
    }

    // Client key/secret, webhook signing secret, AND the PayIn/PayOut
    // callback configuration (payin_callback_url/payout_callback_url,
    // explicitly NULL = "not configured") are created together as ONE
    // atomic operation — a customer never ends up with credentials but no
    // callback configuration row, or vice versa.
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO customer_api_credentials (user_id, client_key, secret_key_hash, secret_key_last4, webhook_signing_secret_encrypted, payin_callback_url, payout_callback_url)
             VALUES (?, ?, ?, ?, ?, NULL, NULL)'
        )->execute([$user['id'], $clientKey, password_hash($secretKeyPlaintext, PASSWORD_DEFAULT), substr($secretKeyPlaintext, -4), $webhookSecretEncrypted]);

        write_audit_log((int) $user['id'], 'api_credentials_provisioned', 'user', (int) $user['id'], [
            'includes' => ['client_key', 'secret_key', 'webhook_signing_secret', 'payin_callback_config', 'payout_callback_config'],
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[settings/api-credentials] provisioning failed: ' . $e->getMessage());
        json_response(false, null, 'Unable to set up your API credentials right now. Please try again.', 500);
    }

    $stmt->execute([$user['id']]);
    $creds = $stmt->fetch();
}

// Lazily backfills a webhook signing secret for accounts provisioned
// before this endpoint generated one atomically above (pre-existing rows
// only) — same one-time-reveal convention as secret_key above, just
// triggered by "never generated yet" instead of "never provisioned yet".
if (!$creds['webhook_signing_secret_encrypted']) {
    $backfillPlaintext = bin2hex(random_bytes(24));
    try {
        $encrypted = gateway_encrypt_secret($backfillPlaintext);
    } catch (Throwable $e) {
        error_log('[settings/api-credentials] ' . $e->getMessage());
        $encrypted = null;
        $backfillPlaintext = null;
    }
    if ($encrypted !== null) {
        $pdo->prepare('UPDATE customer_api_credentials SET webhook_signing_secret_encrypted = ? WHERE user_id = ?')
            ->execute([$encrypted, $user['id']]);
        write_audit_log((int) $user['id'], 'api_webhook_secret_provisioned', 'user', (int) $user['id'], []);
        $webhookSecretPlaintext = $backfillPlaintext;
    }
}

$ipsStmt = $pdo->prepare("SELECT ip_address, status, created_at, updated_at FROM customer_whitelisted_ips WHERE user_id = ? ORDER BY created_at ASC");
$ipsStmt->execute([$user['id']]);

json_response(true, [
    'client_key' => $creds['client_key'],
    'secret_key_masked' => '••••' . $creds['secret_key_last4'],
    'secret_key_plaintext' => $secretKeyPlaintext,
    'bearer_token' => $creds['bearer_token'],
    'bearer_token_generated_at' => $creds['bearer_token_generated_at'],
    'payout_callback_url' => $creds['payout_callback_url'],
    'payin_callback_url' => $creds['payin_callback_url'],
    'webhook_signing_secret_configured' => $webhookSecretPlaintext !== null || !empty($creds['webhook_signing_secret_encrypted']),
    'webhook_signing_secret_plaintext' => $webhookSecretPlaintext,
    'last_verified_at' => $creds['last_verified_at'],
    'whitelisted_ips' => $ipsStmt->fetchAll(),
], 'ok');
