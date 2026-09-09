<?php
/**
 * 7-day successful-settlement trend for ONE gateway — the drill-down behind
 * Dashboard's "Gateway analytics" dropdown. Same shape/role as
 * summary.php::daily_trend() (which backs Deposit/Withdrawal analytics),
 * just scoped by gateway_id instead of by transaction type, and its own
 * endpoint since summary.php is a single fetch-on-load call while this one
 * re-fires on every dropdown change.
 *
 * Not scoped by type (deposit vs withdrawal) — a gateway's settled amount
 * is the same total already surfaced per-gateway on this same page (see
 * public/api/dashboard/summary.php's gateway_health success_amount), so
 * this trend should sum to the same thing over 7 days, not a narrower slice.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = require_auth();
if (!in_array($user['role'], ['admin', 'operator'], true)) {
    json_response(false, null, 'You do not have permission to perform this action.', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$gatewayId = (int) ($_GET['gateway_id'] ?? 0);
if ($gatewayId <= 0) {
    json_response(false, null, 'gateway_id is required.', 422, 'VALIDATION_ERROR');
}

$pdo = db();
$existsStmt = $pdo->prepare('SELECT id FROM payment_gateways WHERE id = ?');
$existsStmt->execute([$gatewayId]);
if (!$existsStmt->fetchColumn()) {
    json_response(false, null, 'Gateway not found.', 404);
}

$points = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(net_amount), 0) FROM transactions WHERE gateway_id = ? AND status = 'success' AND DATE(created_at) = ?"
    );
    $stmt->execute([$gatewayId, $day]);
    $points[] = ['label' => date('D', strtotime($day)), 'value' => (float) $stmt->fetchColumn()];
}

json_response(true, ['trend' => $points], 'ok');
