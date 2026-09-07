<?php
/**
 * Customer's own chargebacks — scoped to the caller, never another
 * merchant's. Admin/operator equivalent is
 * public/api/admin/chargebacks/list.php.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard(['customer']);
$pdo = db();

[$page, $perPage, $offset] = paginate_params(20, 100);

$where = ['cb.user_id = :uid'];
$params = ['uid' => $user['id']];

if (in_array($_GET['status'] ?? '', ['open', 'pending', 'won', 'lost', 'reversed', 'cancelled'], true)) {
    $where[] = 'cb.status = :status';
    $params['status'] = $_GET['status'];
}
if (!empty($_GET['search'])) {
    $needle = '%' . $_GET['search'] . '%';
    $where[] = '(cb.gateway_chargeback_id LIKE :s1 OR t.reference LIKE :s2 OR t.merchant_order_id LIKE :s3)';
    $params['s1'] = $params['s2'] = $params['s3'] = $needle;
}

$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT cb.id, cb.provider, cb.gateway_chargeback_id, cb.amount, cb.fee, cb.total_amount, cb.currency,
            cb.reason, cb.status, cb.due_at, cb.resolved_at, cb.resolution, cb.created_at,
            t.reference AS transaction_reference, t.merchant_order_id
     FROM chargebacks cb
     JOIN transactions t ON t.id = cb.transaction_id
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
    "SELECT COUNT(*) FROM chargebacks cb JOIN transactions t ON t.id = cb.transaction_id WHERE {$whereSql}"
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
