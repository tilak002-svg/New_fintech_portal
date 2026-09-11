<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$user = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

[$page, $perPage, $offset] = paginate_params(10, 100);

$where = ['1=1'];
$params = [];

if (in_array($_GET['type'] ?? '', ['deposit', 'withdrawal'], true)) {
    $where[] = 'ledger.type = :type';
    $params['type'] = $_GET['type'];
}
if (!empty($_GET['from'])) {
    $where[] = 'DATE(ledger.created_at) >= :from';
    $params['from'] = $_GET['from'];
}
if (!empty($_GET['to'])) {
    $where[] = 'DATE(ledger.created_at) <= :to';
    $params['to'] = $_GET['to'];
}
if (!empty($_GET['search'])) {
    // Real (non-emulated) prepared statements require a distinct bound
    // parameter per placeholder occurrence, even when the value repeats.
    $needle = '%' . $_GET['search'] . '%';
    $where[] = '(ledger.reference LIKE :search1 OR ledger.merchant_name LIKE :search2 OR ledger.merchant_email LIKE :search3)';
    $params['search1'] = $params['search2'] = $params['search3'] = $needle;
}
// Filters to a specific configured gateway instance (not just a provider
// type — an operator may run more than one gateway account for the same
// provider). Deliberately not restricted to currently-active gateways so a
// retired/deactivated gateway's historical transactions stay filterable.
if (ctype_digit((string) ($_GET['gateway_id'] ?? ''))) {
    $where[] = 'ledger.gateway_id = :gateway_id';
    $params['gateway_id'] = (int) $_GET['gateway_id'];
}

$whereSql = implode(' AND ', $where);

// The running balance is computed over the full, unfiltered transaction
// history in chronological order (oldest first) so it never shifts just
// because a filter or page changed — filtering/sorting for display happens
// in the outer query, on top of an already-correct running total.
// Requires MySQL 8.0+ or MariaDB 10.2+ for window function support.
$baseSql = "
    SELECT
        t.id, t.type, t.method, t.amount, t.status, t.reference, t.created_at,
        u.name AS merchant_name, u.email AS merchant_email,
        pg.id AS gateway_id, pg.display_name AS gateway_name, pg.provider AS gateway_provider,
        SUM(CASE WHEN t.status = 'success' THEN (CASE WHEN t.type = 'deposit' THEN t.net_amount ELSE -t.net_amount END) ELSE 0 END)
            OVER (ORDER BY t.created_at ASC, t.id ASC) AS running_balance
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN payment_gateways pg ON pg.id = t.gateway_id
";

$listSql = "SELECT * FROM ({$baseSql}) ledger WHERE {$whereSql} ORDER BY ledger.created_at DESC, ledger.id DESC LIMIT :limit OFFSET :offset";
$stmt = db()->prepare($listSql);
foreach ($params as $key => $value) {
    $stmt->bindValue(":{$key}", $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll();

$countSql = "SELECT COUNT(*) FROM ({$baseSql}) ledger WHERE {$whereSql}";
$countStmt = db()->prepare($countSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue(":{$key}", $value);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();

json_response(true, [
    'entries' => $entries,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => max(1, (int) ceil($total / $perPage)),
    ],
], 'ok');
