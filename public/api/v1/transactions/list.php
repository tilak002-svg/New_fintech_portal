<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$merchant = api_guard(['customer']);
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

[$page, $perPage, $offset] = paginate_params(20, 100);

// This endpoint is scoped to the merchant's API activity specifically —
// excludes legacy wallet-style deposits/withdrawals (merchant_order_id
// IS NULL) a merchant may also have from the browser /deposits or
// /withdrawals flow, which stay a separate concept (see the pivot plan's
// Decision 5).
// Aliased as t: payment_gateways (joined below for the sandbox flag) also
// has status/created_at/updated_at columns, so every transactions column
// referenced anywhere in this query must be qualified to avoid an
// ambiguous-column SQL error.
$where = ['t.user_id = :uid', 't.merchant_order_id IS NOT NULL'];
$params = ['uid' => $merchant['id']];

// Merchant-facing vocabulary is payin/payout; translate to the internal
// deposit/withdrawal enum value before querying — see
// includes/functions.php::transaction_type_public_name().
$typeParam = $_GET['type'] ?? '';
if ($typeParam === 'payin') {
    $where[] = 't.type = :type';
    $params['type'] = 'deposit';
} elseif ($typeParam === 'payout') {
    $where[] = 't.type = :type';
    $params['type'] = 'withdrawal';
}

if (in_array($_GET['status'] ?? '', ['pending', 'success', 'failed', 'cancelled', 'refunded'], true)) {
    $where[] = 't.status = :status';
    $params['status'] = $_GET['status'];
}
if (!empty($_GET['from'])) {
    $where[] = 'DATE(t.created_at) >= :from';
    $params['from'] = $_GET['from'];
}
if (!empty($_GET['to'])) {
    $where[] = 'DATE(t.created_at) <= :to';
    $params['to'] = $_GET['to'];
}

$whereSql = implode(' AND ', $where);

$select = "SELECT t.id, t.type, t.amount, t.fee, t.net_amount, t.currency, t.status, t.reference, t.merchant_order_id,
                  t.end_customer_name, t.end_customer_email, t.end_customer_phone,
                  t.beneficiary_name, t.beneficiary_bank_name, t.created_at, t.updated_at,
                  pg.sandbox_mode AS gateway_sandbox_mode
           FROM transactions t LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id
           WHERE {$whereSql} ORDER BY t.created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($select);
foreach ($params as $key => $value) {
    $stmt->bindValue(":{$key}", $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $row['type'] = transaction_type_public_name($row['type']);
}
unset($row);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE {$whereSql}");
foreach ($params as $key => $value) {
    $countStmt->bindValue(":{$key}", $value);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

json_response(true, [
    'transactions' => $rows,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
], 'ok');
