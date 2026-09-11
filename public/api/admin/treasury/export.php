<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$user = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

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
    $needle = '%' . $_GET['search'] . '%';
    $where[] = '(ledger.reference LIKE :search1 OR ledger.merchant_name LIKE :search2 OR ledger.merchant_email LIKE :search3)';
    $params['search1'] = $params['search2'] = $params['search3'] = $needle;
}
// Same gateway filter as list.php — see the comment there.
if (ctype_digit((string) ($_GET['gateway_id'] ?? ''))) {
    $where[] = 'ledger.gateway_id = :gateway_id';
    $params['gateway_id'] = (int) $_GET['gateway_id'];
}

$whereSql = implode(' AND ', $where);

// Same running-balance approach as list.php — see the comment there.
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

// Capped at 5,000 rows per export as a sane safety limit; narrow the date
// range for larger pulls.
$exportSql = "SELECT * FROM ({$baseSql}) ledger WHERE {$whereSql} ORDER BY ledger.created_at DESC, ledger.id DESC LIMIT 5000";
$stmt = db()->prepare($exportSql);
foreach ($params as $key => $value) {
    $stmt->bindValue(":{$key}", $value);
}
$stmt->execute();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="treasury-ledger-' . date('Y-m-d_His') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Timestamp', 'Merchant name', 'Merchant email', 'Service type', 'Transaction ID', 'Gateway', 'Credit (+)', 'Debit (-)', 'Net balance', 'Status']);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        $row['created_at'],
        $row['merchant_name'],
        $row['merchant_email'],
        ucfirst($row['type']),
        $row['reference'],
        $row['gateway_name'] ?: '—',
        $row['type'] === 'deposit' ? number_format((float) $row['amount'], 2, '.', '') : '',
        $row['type'] === 'withdrawal' ? number_format((float) $row['amount'], 2, '.', '') : '',
        number_format((float) $row['running_balance'], 2, '.', ''),
        ucfirst($row['status']),
    ]);
}
fclose($out);

write_audit_log($user['id'], 'treasury_report_exported', 'transaction', null, [
    'filters' => array_intersect_key($_GET, array_flip(['type', 'from', 'to', 'search', 'gateway_id'])),
]);
exit;
