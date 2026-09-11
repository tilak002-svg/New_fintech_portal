<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$userId = (int) ($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    json_response(false, null, 'A customer is required.', 422);
}

$pdo = db();
$userStmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? AND role = "customer"');
$userStmt->execute([$userId]);
$targetUser = $userStmt->fetch();

if (!$targetUser) {
    json_response(false, null, 'Customer not found.', 404);
}

$gatewaysStmt = $pdo->prepare(
    'SELECT pg.id, pg.display_name, pg.provider, pg.status, pg.priority AS default_priority,
            mga.priority AS assigned_priority, mga.is_enabled
     FROM payment_gateways pg
     LEFT JOIN merchant_gateway_assignments mga ON mga.gateway_id = pg.id AND mga.user_id = ?
     ORDER BY pg.priority ASC, pg.id ASC'
);
$gatewaysStmt->execute([$userId]);

json_response(true, [
    'user' => $targetUser,
    'gateways' => $gatewaysStmt->fetchAll(),
], 'ok');
