<?php
/**
 * Full detail + event timeline for a single transaction (PayIn or PayOut).
 * Customer/merchant callers are scoped to their own transactions; admin/
 * operator can view any. Never returns gateway credentials or the full
 * beneficiary account number — same redaction rules as the rest of the app.
 *
 * The timeline is assembled from audit_logs rows already written elsewhere
 * (payin_service.php, payout_service.php, gateway_webhooks.php,
 * customer_webhooks.php) via write_audit_log(..., 'transaction', $txnId, ...)
 * — no separate events table, this endpoint just reads and labels them.
 */

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard();
$isOperator = in_array($user['role'], ['admin', 'operator'], true);
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(false, null, 'A transaction id is required.', 422);
}

$where = 't.id = :id';
$params = ['id' => $id];
if (!$isOperator) {
    $where .= ' AND t.user_id = :uid';
    $params['uid'] = $user['id'];
}

$stmt = $pdo->prepare(
    "SELECT t.id, t.user_id, t.type, t.method, t.amount, t.fee, t.net_amount, t.currency, t.status, t.reference,
            t.destination, t.gateway_id, t.gateway_txn_id, t.merchant_order_id,
            t.end_customer_name, t.end_customer_email, t.end_customer_phone,
            t.beneficiary_name, t.beneficiary_account_number, t.beneficiary_ifsc, t.beneficiary_bank_name,
            t.beneficiary_phone, t.beneficiary_address, t.created_at, t.updated_at,
            u.name AS user_name, u.email AS user_email,
            pg.display_name AS gateway_name, pg.provider AS gateway_provider, pg.sandbox_mode AS gateway_sandbox_mode,
            ps.status AS session_status, ps.expires_at AS session_expires_at
     FROM transactions t
     JOIN users u ON u.id = t.user_id
     LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id
     LEFT JOIN payment_sessions ps ON ps.transaction_id = t.id
     WHERE {$where}
     LIMIT 1"
);
$stmt->execute($params);
$txn = $stmt->fetch();

if (!$txn) {
    json_response(false, null, 'Transaction not found.', 404);
}

// Never return a full beneficiary account number over the API — last 4 only,
// same rule as the outbound customer webhook payload (customer_webhooks.php).
if (!empty($txn['beneficiary_account_number'])) {
    $txn['beneficiary_account_last4'] = substr($txn['beneficiary_account_number'], -4);
}
unset($txn['beneficiary_account_number']);

$txn['type'] = transaction_type_public_name($txn['type']);

/** Maps an audit_logs action into a human-readable timeline entry. */
function transaction_timeline_label(string $action, array $metadata, string $gatewayName): array
{
    switch ($action) {
        case 'payin_created':
        case 'payout_created':
            $suffix = $gatewayName !== '' ? " — {$gatewayName} selected" : '';
            return ['label' => "Transaction created{$suffix}", 'tone' => 'neutral'];
        case 'payin_gateway_unavailable':
        case 'payout_gateway_unavailable':
            return ['label' => 'No gateway had capacity to accept this transaction', 'tone' => 'danger'];
        case 'payin_gateway_request_sent':
        case 'payout_gateway_request_sent':
            $suffix = $gatewayName !== '' ? " to {$gatewayName}" : '';
            return ['label' => "Gateway request sent{$suffix}", 'tone' => 'neutral'];
        case 'payin_gateway_order_ambiguous':
        case 'payout_gateway_payout_ambiguous':
            return ['label' => 'Gateway response was ambiguous — awaiting confirmation', 'tone' => 'warning'];
        case 'payin_gateway_order_failed':
        case 'payout_gateway_payout_failed':
            return ['label' => 'Gateway rejected the request', 'tone' => 'danger'];
        case 'payin_sandbox_settled':
        case 'payout_sandbox_settled':
            return ['label' => 'Transaction completed instantly — no live gateway configured (local sandbox)', 'tone' => 'success'];
        case 'reconciliation_resolved':
            $outcome = (string) ($metadata['outcome'] ?? 'resolved');
            return ['label' => "Resolved by reconciliation — marked {$outcome} (no webhook was received)", 'tone' => $outcome === 'success' ? 'success' : 'danger'];
        case 'webhook_processed':
            $outcome = (string) ($metadata['outcome'] ?? 'resolved');
            return ['label' => "Gateway webhook received — transaction marked {$outcome}", 'tone' => $outcome === 'success' ? 'success' : 'danger'];
        case 'customer_webhook_delivered':
            return ['label' => 'Callback sent to your website', 'tone' => 'success'];
        case 'customer_webhook_delivery_failed':
            return ['label' => 'Callback delivery to your website failed', 'tone' => 'warning'];
        default:
            return ['label' => ucfirst(str_replace('_', ' ', $action)), 'tone' => 'neutral'];
    }
}

$auditStmt = $pdo->prepare(
    "SELECT action, metadata, created_at FROM audit_logs
     WHERE target_type = 'transaction' AND target_id = :id
     ORDER BY created_at ASC, id ASC"
);
$auditStmt->execute(['id' => $id]);
$auditRows = $auditStmt->fetchAll();

$timeline = [];
foreach ($auditRows as $ev) {
    $metadata = json_decode($ev['metadata'] ?? '', true);
    $mapped = transaction_timeline_label($ev['action'], is_array($metadata) ? $metadata : [], $txn['gateway_name'] ?? '');
    $timeline[] = [
        'label' => $mapped['label'],
        'tone' => $mapped['tone'],
        'occurred_at' => $ev['created_at'],
    ];
}

// Callback/webhook delivery — derived from the SAME audit rows the
// timeline reads (customer_webhooks.php writes exactly one
// customer_webhook_delivered/customer_webhook_delivery_failed row per
// delivery attempt), plus the callback URL currently on file. The URL is
// read live rather than snapshotted at delivery time, so this always
// reflects the customer's current Settings — intentional, since a stale
// snapshot would be actively misleading for debugging a fresh retry.
$credStmt = $pdo->prepare('SELECT payin_callback_url, payout_callback_url FROM customer_api_credentials WHERE user_id = ?');
$credStmt->execute([$txn['user_id']]);
$creds = $credStmt->fetch();
$callbackUrl = $creds ? ($txn['type'] === 'payin' ? $creds['payin_callback_url'] : $creds['payout_callback_url']) : null;

$deliveryStatus = 'not_configured';
$lastAttemptAt = null;
$attempts = 0;
$failureReason = null;

if ($callbackUrl) {
    $deliveryStatus = in_array($txn['status'], ['success', 'failed'], true) ? 'pending' : 'not_applicable';
    foreach ($auditRows as $ev) {
        if ($ev['action'] === 'customer_webhook_delivered') {
            $attempts++;
            $deliveryStatus = 'delivered';
            $lastAttemptAt = $ev['created_at'];
            $failureReason = null;
        } elseif ($ev['action'] === 'customer_webhook_delivery_failed') {
            $attempts++;
            $deliveryStatus = 'failed';
            $lastAttemptAt = $ev['created_at'];
            $metadata = json_decode($ev['metadata'] ?? '', true);
            $failureReason = is_array($metadata) ? ($metadata['reason'] ?? null) : null;
        }
    }
}

$callback = [
    'url' => $callbackUrl,
    'status' => $deliveryStatus,
    'attempts' => $attempts,
    'last_attempt_at' => $lastAttemptAt,
    'failure_reason' => $failureReason,
];

unset($txn['user_id']);

json_response(true, ['transaction' => $txn, 'timeline' => $timeline, 'callback' => $callback], 'ok');
