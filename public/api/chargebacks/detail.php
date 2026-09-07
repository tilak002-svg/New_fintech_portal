<?php
/**
 * Full chargeback detail + event timeline. Customer callers (session or
 * bearer token) are scoped to their own chargebacks only — the same IDOR
 * guard pattern as public/api/transactions/detail.php; admin/operator can
 * view any. Never returns gateway credentials, other merchants' data, or
 * raw webhook payloads to a customer (those stay admin-only, and even then
 * only as a redacted summary).
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard();
$isOperator = in_array($user['role'], ['admin', 'operator'], true);
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(false, null, 'A chargeback id is required.', 422);
}

$where = 'cb.id = :id';
$params = ['id' => $id];
if (!$isOperator) {
    $where .= ' AND cb.user_id = :uid';
    $params['uid'] = $user['id'];
}

$stmt = $pdo->prepare(
    "SELECT cb.id, cb.transaction_id, cb.user_id, cb.gateway_id, cb.provider, cb.gateway_chargeback_id, cb.gateway_reference,
            cb.amount, cb.fee, cb.total_amount, cb.currency, cb.reason_code, cb.reason, cb.status, cb.provider_status,
            cb.financial_impact_applied_at, cb.initiated_at, cb.due_at, cb.resolved_at, cb.resolution,
            cb.created_at, cb.updated_at,
            t.reference AS transaction_reference, t.merchant_order_id, t.amount AS transaction_amount, t.status AS transaction_status,
            u.name AS user_name, u.email AS user_email,
            pg.display_name AS gateway_name
     FROM chargebacks cb
     JOIN transactions t ON t.id = cb.transaction_id
     JOIN users u ON u.id = cb.user_id
     LEFT JOIN payment_gateways pg ON pg.id = cb.gateway_id
     WHERE {$where}
     LIMIT 1"
);
$stmt->execute($params);
$chargeback = $stmt->fetch();

if (!$chargeback) {
    json_response(false, null, 'Chargeback not found.', 404);
}

if (!$isOperator) {
    unset($chargeback['user_name'], $chargeback['user_email']);
}

$eventsStmt = $pdo->prepare(
    'SELECT event_type, normalized_status, provider_status, applied, skip_reason, occurred_at, created_at
     FROM chargeback_events WHERE chargeback_id = ? ORDER BY occurred_at ASC, id ASC'
);
$eventsStmt->execute([$id]);
$timeline = array_map(static function (array $ev) use ($isOperator): array {
    $entry = [
        'status' => $ev['normalized_status'],
        'applied' => (bool) $ev['applied'],
        'occurred_at' => $ev['occurred_at'],
        'recorded_at' => $ev['created_at'],
    ];
    // Raw provider event type / skip reason is diagnostic detail — admin
    // only, never surfaced to the merchant.
    if ($isOperator) {
        $entry['event_type'] = $ev['event_type'];
        $entry['provider_status'] = $ev['provider_status'];
        $entry['skip_reason'] = $ev['skip_reason'];
    }
    return $entry;
}, $eventsStmt->fetchAll());

$ledgerEntries = [];
if ($isOperator) {
    $ledgerStmt = $pdo->prepare(
        'SELECT entry_type, amount, available_balance_after, receivable_balance_after, description, created_at
         FROM wallet_ledger WHERE reference_type = "chargeback" AND reference_id = ? ORDER BY created_at ASC, id ASC'
    );
    $ledgerStmt->execute([$id]);
    $ledgerEntries = $ledgerStmt->fetchAll();
}

json_response(true, [
    'chargeback' => $chargeback,
    'timeline' => $timeline,
    'ledger' => $ledgerEntries,
], 'ok');
