<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = require_auth();
$pdo = db();
$isOperator = in_array($user['role'], ['admin', 'operator'], true);

$data = [];

if (!$isOperator) {
    $walletStmt = $pdo->prepare('SELECT available_balance, pending_balance, currency FROM wallets WHERE user_id = ?');
    $walletStmt->execute([$user['id']]);
    $wallet = $walletStmt->fetch() ?: ['available_balance' => '0.00', 'pending_balance' => '0.00', 'currency' => 'INR'];
    $data['wallet'] = $wallet;

    $scopeSql = 'WHERE user_id = :uid';
    $params = ['uid' => $user['id']];
} else {
    $scopeSql = 'WHERE 1=1';
    $params = [];
}

$today = date('Y-m-d');
$todayStmt = $pdo->prepare("SELECT COUNT(*) FROM transactions {$scopeSql} AND DATE(created_at) = :today");
$todayStmt->execute($params + ['today' => $today]);
$data['today_count'] = (int) $todayStmt->fetchColumn();

/** Total + per-status count/amount breakdown for one transaction type — backs the Deposits/Withdrawals report cards. */
function type_report(PDO $pdo, string $scopeSql, array $params, string $type): array
{
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c, COALESCE(SUM(amount), 0) s FROM transactions {$scopeSql} AND type = :type GROUP BY status");
    $stmt->execute($params + ['type' => $type]);

    $report = [
        'total' => ['count' => 0, 'amount' => '0.00'],
        'success' => ['count' => 0, 'amount' => '0.00'],
        'pending' => ['count' => 0, 'amount' => '0.00'],
        'failed' => ['count' => 0, 'amount' => '0.00'],
    ];
    foreach ($stmt->fetchAll() as $row) {
        $report['total']['count'] += (int) $row['c'];
        $report['total']['amount'] = bcadd($report['total']['amount'], (string) $row['s'], 2);
        if (isset($report[$row['status']])) {
            $report[$row['status']] = ['count' => (int) $row['c'], 'amount' => (string) $row['s']];
        }
    }
    return $report;
}

$data['deposits_report'] = type_report($pdo, $scopeSql, $params, 'deposit');
$data['withdrawals_report'] = type_report($pdo, $scopeSql, $params, 'withdrawal');

/** Successful daily amount for one type, last 7 days — backs the two trend charts. */
function daily_trend(PDO $pdo, string $scopeSql, array $params, string $type): array
{
    $points = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions {$scopeSql} AND type = :type AND status = 'success' AND DATE(created_at) = :day");
        $stmt->execute($params + ['type' => $type, 'day' => $day]);
        $points[] = ['label' => date('D', strtotime($day)), 'value' => (float) $stmt->fetchColumn()];
    }
    return $points;
}

$data['deposits_trend'] = daily_trend($pdo, $scopeSql, $params, 'deposit');
$data['withdrawals_trend'] = daily_trend($pdo, $scopeSql, $params, 'withdrawal');

/** Today's total amount + count for merchant-API-driven activity only
 * (merchant_order_id IS NOT NULL) — backs the compact PayIns/PayOuts
 * dashboard cards, distinct from the legacy wallet deposits_report above. */
function today_api_total(PDO $pdo, string $scopeSql, array $params, string $type, string $today): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c, COALESCE(SUM(amount), 0) s FROM transactions {$scopeSql}
         AND type = :type AND merchant_order_id IS NOT NULL AND DATE(created_at) = :today"
    );
    $stmt->execute($params + ['type' => $type, 'today' => $today]);
    $row = $stmt->fetch();
    return ['count' => (int) ($row['c'] ?? 0), 'amount' => (string) ($row['s'] ?? '0.00')];
}

$data['payins_today'] = today_api_total($pdo, $scopeSql, $params, 'deposit', $today);
$data['payouts_today'] = today_api_total($pdo, $scopeSql, $params, 'withdrawal', $today);

/** All-time successful amount/count for merchant-API-driven activity —
 * backs the customer dashboard's "Total PayIns"/"Total PayOuts" cards. */
function total_api_amount(PDO $pdo, string $scopeSql, array $params, string $type): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) c, COALESCE(SUM(amount), 0) s FROM transactions {$scopeSql}
         AND type = :type AND merchant_order_id IS NOT NULL AND status = 'success'"
    );
    $stmt->execute($params + ['type' => $type]);
    $row = $stmt->fetch();
    return ['count' => (int) ($row['c'] ?? 0), 'amount' => (string) ($row['s'] ?? '0.00')];
}

$data['total_payins'] = total_api_amount($pdo, $scopeSql, $params, 'deposit');
$data['total_payouts'] = total_api_amount($pdo, $scopeSql, $params, 'withdrawal');

if ($isOperator) {
    // Gateway health strip — admin dashboard only. Success rate is
    // definite-outcome-only (success / (success+failed)), excluding
    // pending/cancelled/refunded from the denominator so an in-flight
    // transaction never drags the rate down before it's actually resolved.
    $gatewaysStmt = $pdo->query(
        "SELECT g.id, g.display_name, g.provider, g.status, g.sandbox_mode, g.priority,
                g.daily_limit_amount, g.auto_paused_until,
                (SELECT used_amount FROM gateway_daily_usage WHERE gateway_id = g.id AND usage_date = CURDATE()) AS used_today,
                COALESCE(SUM(t.status = 'success'), 0) AS success_count,
                COALESCE(SUM(t.status = 'failed'), 0) AS failed_count
         FROM payment_gateways g
         LEFT JOIN transactions t ON t.gateway_id = g.id AND t.status IN ('success', 'failed')
         GROUP BY g.id
         ORDER BY g.priority ASC, g.id ASC"
    );
    $gateways = [];
    foreach ($gatewaysStmt->fetchAll() as $g) {
        $resolved = (int) $g['success_count'] + (int) $g['failed_count'];
        $gateways[] = [
            'id' => (int) $g['id'],
            'display_name' => $g['display_name'],
            'provider' => $g['provider'],
            'status' => $g['status'],
            'priority' => (int) $g['priority'],
            'sandbox_mode' => (bool) $g['sandbox_mode'],
            'auto_paused' => $g['auto_paused_until'] !== null && $g['auto_paused_until'] > gmdate('Y-m-d H:i:s'),
            'used_today' => $g['used_today'] ?? '0.00',
            'daily_limit_amount' => $g['daily_limit_amount'],
            'success_rate' => $resolved > 0 ? round(($g['success_count'] / $resolved) * 100, 1) : null,
        ];
    }
    $data['gateway_health'] = $gateways;
} else {
    // Integration setup checklist — customer dashboard only. Each step
    // reflects real, already-persisted state (no separate "progress"
    // table) so it can never drift from what's actually configured.
    $credStmt = $pdo->prepare(
        'SELECT payin_callback_url, payout_callback_url FROM customer_api_credentials WHERE user_id = ?'
    );
    $credStmt->execute([$user['id']]);
    $creds = $credStmt->fetch();

    $ipCountStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_whitelisted_ips WHERE user_id = ?');
    $ipCountStmt->execute([$user['id']]);

    $apiTestedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM transactions WHERE user_id = ? AND merchant_order_id IS NOT NULL'
    );
    $apiTestedStmt->execute([$user['id']]);

    $liveTxnStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM transactions t
         JOIN payment_gateways g ON g.id = t.gateway_id
         WHERE t.user_id = ? AND t.merchant_order_id IS NOT NULL AND t.status = 'success' AND g.sandbox_mode = 0"
    );
    $liveTxnStmt->execute([$user['id']]);

    $data['onboarding'] = [
        'account_created' => true,
        'api_credentials_generated' => (bool) $creds,
        'ip_whitelist_configured' => (int) $ipCountStmt->fetchColumn() > 0,
        'callback_configured' => $creds && (!empty($creds['payin_callback_url']) || !empty($creds['payout_callback_url'])),
        'api_tested' => (int) $apiTestedStmt->fetchColumn() > 0,
        'production_integration' => (int) $liveTxnStmt->fetchColumn() > 0,
    ];
}

$recentSql = $isOperator
    ? "SELECT t.id, t.type, t.amount, t.currency, t.status, t.reference, t.created_at, u.name AS user_name
       FROM transactions t JOIN users u ON u.id = t.user_id
       ORDER BY t.created_at DESC LIMIT 6"
    : "SELECT id, type, amount, currency, status, reference, created_at FROM transactions WHERE user_id = :uid ORDER BY created_at DESC LIMIT 6";
$recentStmt = $pdo->prepare($recentSql);
$recentStmt->execute($isOperator ? [] : ['uid' => $user['id']]);
$data['recent'] = $recentStmt->fetchAll();

json_response(true, $data, 'ok');
