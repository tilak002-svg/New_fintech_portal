<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/gateway_selector.php';
require_once __DIR__ . '/../../../../includes/gateway_providers/dispatch.php';

$user = require_auth();
require_role($user, 'admin');

// Providers with an actual receiver at /api/webhooks/{provider}.php - any
// other provider falls back to the generic /api/webhooks/gateway.php.
$providerWebhookPaths = ['razorpay' => 'razorpay.php', 'cashfree' => 'cashfree.php'];

$pdo = db();
$stmt = $pdo->query(
    'SELECT id, display_name, provider, api_key_last4, public_key, payout_account_number, sandbox_mode, status, is_default, priority, daily_limit_amount,
            consecutive_failures, auto_paused_until,
            (webhook_secret_encrypted IS NOT NULL) AS webhook_configured,
            (api_key_encrypted IS NOT NULL) AS has_live_secret, created_at, updated_at
     FROM payment_gateways ORDER BY is_default DESC, priority ASC, created_at ASC'
);
$gateways = $stmt->fetchAll();

foreach ($gateways as &$gateway) {
    $usage = gateway_daily_usage_snapshot($pdo, (int) $gateway['id']);
    $gateway['used_today'] = $usage['used_amount'];
    $gateway['transaction_count_today'] = $usage['transaction_count'];
    $gateway['remaining_today'] = $gateway['daily_limit_amount'] === null
        ? null
        : money_sub($gateway['daily_limit_amount'], $usage['used_amount']);
    $gateway['auto_paused'] = $gateway['auto_paused_until'] !== null && $gateway['auto_paused_until'] > gmdate('Y-m-d H:i:s');
    $gateway['webhook_configured'] = (bool) $gateway['webhook_configured'];
    $gateway['sandbox_mode'] = (bool) $gateway['sandbox_mode'];
    // gateway_supports_live_order_creation() checks a real api_key_encrypted
    // value, which is never exposed via this API — a truthy placeholder
    // standing in for "a secret exists" satisfies that check without
    // leaking the actual encrypted blob in the response.
    $liveCheckRow = [
        'provider' => $gateway['provider'],
        'public_key' => $gateway['public_key'],
        'api_key_encrypted' => $gateway['has_live_secret'] ? '1' : null,
        'payout_account_number' => $gateway['payout_account_number'],
    ];
    $gateway['live_integration'] = gateway_supports_live_order_creation($liveCheckRow);
    $gateway['live_payout'] = gateway_supports_live_payout($liveCheckRow);
    unset($gateway['has_live_secret'], $gateway['payout_account_number']);
    $webhookPath = $providerWebhookPaths[$gateway['provider']] ?? 'gateway.php';
    $gateway['webhook_url'] = APP_URL . "/api/webhooks/{$webhookPath}?gateway_id={$gateway['id']}";
}
unset($gateway);

json_response(true, ['gateways' => $gateways], 'ok');