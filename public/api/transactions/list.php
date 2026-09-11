<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard();
$isOperator = in_array($user['role'], ['admin', 'operator'], true);
$pdo = db();

[$page, $perPage, $offset] = paginate_params(20, 100);

$where = ['1=1'];
$params = [];

if (!$isOperator) {
    $where[] = 't.user_id = :uid';
    $params['uid'] = $user['id'];
}

if (in_array($_GET['type'] ?? '', ['deposit', 'withdrawal'], true)) {
    $where[] = 't.type = :type';
    $params['type'] = $_GET['type'];
}

// Admin/operator only — an exact-match "view this one customer's
// transactions" filter, distinct from the free-text search above (which
// LIKE-matches name/email and can pull in unrelated partial matches). A
// customer viewing their own transactions is already scoped to themselves
// regardless, so this filter is meaningless (and ignored) on that path.
if ($isOperator && !empty($_GET['user_id']) && ctype_digit((string) $_GET['user_id'])) {
    $where[] = 't.user_id = :filter_user_id';
    $params['filter_user_id'] = (int) $_GET['user_id'];
}

// Additive filter distinguishing merchant-API-driven activity (PayIns/
// PayOuts, merchant_order_id set) from legacy browser-wallet activity —
// used by pages/admin/payins.php and admin/payouts.php. Omitted entirely
// (the default), this endpoint's behavior is unchanged.
if (($_GET['source'] ?? '') === 'api') {
    $where[] = 't.merchant_order_id IS NOT NULL';
} elseif (($_GET['source'] ?? '') === 'wallet') {
    $where[] = 't.merchant_order_id IS NULL';
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

if (!empty($_GET['search'])) {
    // Real (non-emulated) prepared statements require a distinct bound
    // parameter per placeholder occurrence, even when the value repeats.
    // Covers both PayIn (end_customer_*) and PayOut (beneficiary_name)
    // fields in one shared clause — a PayIn row's beneficiary_name is
    // NULL (and vice versa), so LIKE against it is simply false, never
    // an error. This must stay in sync with what admin/payins.php and
    // admin/payouts.php's search field labels promise.
    $needle = '%' . $_GET['search'] . '%';
    if ($isOperator) {
        $where[] = '(t.reference LIKE :search1 OR u.name LIKE :search2 OR u.email LIKE :search3
                      OR t.merchant_order_id LIKE :search4 OR t.end_customer_name LIKE :search5
                      OR t.end_customer_email LIKE :search6 OR t.beneficiary_name LIKE :search7
                      OR t.gateway_txn_id LIKE :search8)';
        $params['search1'] = $params['search2'] = $params['search3'] = $params['search4']
            = $params['search5'] = $params['search6'] = $params['search7'] = $params['search8'] = $needle;
    } else {
        $where[] = '(t.reference LIKE :search1 OR t.merchant_order_id LIKE :search2
                      OR t.end_customer_name LIKE :search3 OR t.end_customer_email LIKE :search4
                      OR t.beneficiary_name LIKE :search5 OR t.gateway_txn_id LIKE :search6)';
        $params['search1'] = $params['search2'] = $params['search3'] = $params['search4']
            = $params['search5'] = $params['search6'] = $needle;
    }
}

// Provider filter — matches the canonical provider list in
// public/api/admin/gateways/create.php. Requires joining payment_gateways
// (added to both the row and count queries below).
$allowedProviders = ['razorpay', 'cashfree', 'payu', 'stripe', 'paypal', 'other'];
if (in_array($_GET['provider'] ?? '', $allowedProviders, true)) {
    $where[] = 'pg.provider = :provider';
    $params['provider'] = $_GET['provider'];
}

$sortMap = [
    'newest' => 't.created_at DESC',
    'oldest' => 't.created_at ASC',
    'amount_desc' => 't.amount DESC',
    'amount_asc' => 't.amount ASC',
];
$sort = $sortMap[$_GET['sort'] ?? 'newest'] ?? $sortMap['newest'];

$whereSql = implode(' AND ', $where);

// merchant_order_id/end_customer_*/beneficiary_* and the joined gateway's
// sandbox_mode are additive columns (NULL for legacy wallet rows/rows with
// no gateway) — consumed by pages/admin/payins.php and admin/payouts.php;
// the existing Transactions page ignores columns it doesn't render.
$select = $isOperator
    ? "SELECT t.id, t.type, t.method, t.amount, t.fee, t.net_amount, t.currency, t.status, t.reference, t.created_at,
              t.merchant_order_id, t.end_customer_name, t.end_customer_email, t.end_customer_phone,
              t.beneficiary_name, t.beneficiary_account_number, t.beneficiary_ifsc, t.beneficiary_bank_name,
              pg.sandbox_mode AS gateway_sandbox_mode, u.name AS user_name, u.email AS user_email
       FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id
       WHERE {$whereSql} ORDER BY {$sort} LIMIT :limit OFFSET :offset"
    : "SELECT t.id, t.type, t.method, t.amount, t.fee, t.net_amount, t.currency, t.status, t.reference, t.created_at,
              t.merchant_order_id, t.end_customer_name, t.end_customer_email, t.end_customer_phone,
              t.beneficiary_name, t.beneficiary_account_number, t.beneficiary_ifsc, t.beneficiary_bank_name,
              pg.sandbox_mode AS gateway_sandbox_mode
       FROM transactions t LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id
       WHERE {$whereSql} ORDER BY {$sort} LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($select);
foreach ($params as $key => $value) {
    $stmt->bindValue(":{$key}", $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$transactions = $stmt->fetchAll();

// Never return a full beneficiary account number over the API — last 4
// only, same redaction rule transactions/detail.php already applies.
foreach ($transactions as &$row) {
    if (!empty($row['beneficiary_account_number'])) {
        $row['beneficiary_account_last4'] = substr($row['beneficiary_account_number'], -4);
    }
    unset($row['beneficiary_account_number']);
}
unset($row);

$countSql = $isOperator
    ? "SELECT COUNT(*) FROM transactions t JOIN users u ON u.id = t.user_id LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id WHERE {$whereSql}"
    : "SELECT COUNT(*) FROM transactions t LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id WHERE {$whereSql}";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue(":{$key}", $value);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

json_response(true, [
    'transactions' => $transactions,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / $perPage),
    ],
], 'ok');
