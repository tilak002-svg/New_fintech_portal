<?php
/**
 * Cashfree webhook receiver. Verifies Cashfree's actual signature scheme
 * (timestamp+body HMAC-SHA256, base64) and payload shape (see
 * includes/gateway_providers/cashfree.php), then hands off to the same
 * provider-agnostic idempotency/settlement core every other gateway uses.
 *
 * Configure this URL in the Cashfree dashboard's webhook settings:
 *   {APP_URL}/api/webhooks/cashfree.php?gateway_id={id}
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/gateway_secrets.php';
require_once __DIR__ . '/../../../includes/gateway_webhooks.php';
require_once __DIR__ . '/../../../includes/gateway_providers/cashfree.php';
require_once __DIR__ . '/../../../includes/chargeback_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$gatewayId = (int) ($_GET['gateway_id'] ?? 0);
if ($gatewayId <= 0) {
    json_response(false, null, 'gateway_id is required.', 400);
}

$pdo = db();
$stmt = $pdo->prepare('SELECT id, provider, status, webhook_secret_encrypted FROM payment_gateways WHERE id = ?');
$stmt->execute([$gatewayId]);
$gateway = $stmt->fetch();

if (!$gateway || $gateway['status'] !== 'active' || $gateway['provider'] !== 'cashfree') {
    json_response(false, null, 'Unknown, inactive, or non-Cashfree gateway.', 404);
}
if (!$gateway['webhook_secret_encrypted']) {
    error_log("[webhooks/cashfree] gateway {$gatewayId} has no webhook secret configured");
    json_response(false, null, 'Webhook not configured for this gateway.', 409);
}

$rawBody = file_get_contents('php://input');
$timestampHeader = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$signatureHeader = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

try {
    $secret = gateway_decrypt_secret($gateway['webhook_secret_encrypted']);
} catch (Throwable $e) {
    error_log('[webhooks/cashfree] ' . $e->getMessage());
    json_response(false, null, 'Unable to verify this webhook right now.', 500);
}

if (!cashfree_verify_webhook_signature($rawBody, $timestampHeader, $signatureHeader, $secret)) {
    write_audit_log(null, 'webhook_signature_invalid', 'payment_gateway', $gatewayId, ['provider' => 'cashfree']);
    json_response(false, null, 'Invalid signature.', 401);
}

$rawPayload = json_decode($rawBody, true);
if (!is_array($rawPayload)) {
    json_response(false, null, 'Malformed JSON body.', 400);
}

$chargebackEvent = cashfree_parse_chargeback_webhook_payload($rawPayload);
if ($chargebackEvent !== null) {
    $result = process_chargeback_event($pdo, $gatewayId, 'cashfree', $chargebackEvent);
    json_response($result['status'] < 400, null, $result['message'], $result['status']);
}

$mapped = cashfree_parse_webhook_payload($rawPayload);
if ($mapped === null) {
    json_response(true, null, 'Event not actionable - ignored.', 200);
}

$mapped['raw'] = $rawPayload;

$result = process_gateway_webhook($pdo, $gatewayId, $mapped);
json_response($result['status'] < 400, null, $result['message'], $result['status']);
