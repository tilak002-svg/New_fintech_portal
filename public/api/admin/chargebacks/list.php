<?php
/**
 * Admin/operator chargeback list — every merchant's disputes, with filters.
 * Customer-facing equivalent is public/api/chargebacks/list.php, scoped to
 * the caller's own chargebacks only.
 */
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$user = require_auth();
require_role($user, 'admin', 'operator');

$pdo = db();

[$page, $perPage, $offset] = paginate_params(20, 100);

$where = ['1=1'];
$params = [];

if (in_array($_GET['status'] ?? '', ['open', 'pending', 'won', 'lost', 'reversed', 'cancelled'], true)) {
    $where[] = 'cb.status = :status';
    $params['status'] = $_GET['status'];
}
if (!empty($_GET['provider'])) {
    $where[] = 'cb.provider = :provider';
    $params['provider'] = $_GET['provider'];
}
if (!empty($_GET['gateway_id'])) {
    $where[] = 'cb.gateway_id = :gateway_id';
    $params['gateway_id'] = (int) $_GET['gateway_id'];
}
if (!empty($_GET['user_id'])) {
    $where[] = 'cb.user_id = :user_id';
    $params['user_id'] = (int) $_GET['user_id'];
}
if (!empty($_GET['from'])) {
    $where[] = 'DATE(cb.created_at) >= :from';
    $params['from'] = $_GET['from'];
}
if (!empty($_GET['to'])) {
    $where[] = 'DATE(cb.created_at) <= :to';
    $params['to'] = $_GET['to'];
}
if (isset($_GET['min_amount']) && $_GET['min_amount'] !== '') {
    $where[] = 'cb.total_amount >= :min_amount';
    $params['min_amount'] = $_GET['min_amount'];
}
if (isset($_GET['max_amount']) && $_GET['max_amount'] !== '') {
    $where[] = 'cb.total_amount <= :max_amount';
    $params['max_amount'] = $_GET['max_amount'];
}
if (!empty($_GET['search'])) {
    $needle = '%' . $_GET['search'] . '%';
    $where[] = '(cb.gateway_chargeback_id LIKE :s1 OR t.reference LIKE :s2 OR t.merchant_order_id LIKE :s3 OR u.name LIKE :s4 OR u.email LIKE :s5)';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = $params['s5'] = $needle;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT cb.id, cb.provider, cb.gateway_chargeback_id, cb.amount, cb.fee, cb.total_amount, cb.currency,
            cb.reason_code, cb.reason, cb.status, cb.provider_status, cb.initiated_at, cb.due_at, cb.resolved_at,
            cb.created_at, cb.updated_at,
            t.reference AS transaction_reference, t.merchant_order_id,
            u.id AS user_id, u.name AS user_name, u.email AS user_email,
            pg.display_name AS gateway_name
     FROM chargebacks cb
     JOIN transactions t ON t.id = cb.transaction_id
     JOIN users u ON u.id = cb.user_id
     LEFT JOIN payment_gateways pg ON pg.id = cb.gateway_id
     WHERE {$whereSql}
     ORDER BY cb.created_at DESC
     LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(":{$key}", $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$chargebacks = $stmt->fetchAll();

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM chargebacks cb
     JOIN transactions t ON t.id = cb.transaction_id
     JOIN users u ON u.id = cb.user_id
     WHERE {$whereSql}"
);
foreach ($params as $key => $value) {
    $countStmt->bindValue(":{$key}", $value);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

json_response(true, [
    'chargebacks' => $chargebacks,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
], 'ok');
