<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = (int) ($input['user_id'] ?? 0);
$assignments = is_array($input['assignments'] ?? null) ? $input['assignments'] : [];

if ($userId <= 0) {
    json_response(false, null, 'A customer is required.', 422);
}

$pdo = db();
$userStmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ? AND role = "customer"');
$userStmt->execute([$userId]);
$targetUser = $userStmt->fetch();

if (!$targetUser) {
    json_response(false, null, 'Customer not found.', 404);
}

// Only gateways that exist are acceptable — build the valid id set once
// rather than trusting the client's payload.
$validGatewayIds = $pdo->query('SELECT id FROM payment_gateways')->fetchAll(PDO::FETCH_COLUMN);
$validGatewayIds = array_flip(array_map('intval', $validGatewayIds));

$clean = [];
$seenPriorities = [];
foreach ($assignments as $row) {
    $gatewayId = (int) ($row['gateway_id'] ?? 0);
    $priority = (int) ($row['priority'] ?? 0);
    $enabled = !empty($row['enabled']);

    if (!isset($validGatewayIds[$gatewayId])) {
        json_response(false, null, 'One of the selected gateways no longer exists.', 422);
    }
    if ($priority <= 0) {
        json_response(false, null, 'Priority must be a positive number.', 422);
    }
    // A gateway submitted more than once would otherwise violate
    // uq_merchant_gateway inside the same batch — reject rather than
    // silently keeping only the last occurrence.
    if (isset($clean[$gatewayId])) {
        json_response(false, null, 'Each gateway can only be assigned once.', 422);
    }
    // Two enabled gateways can never share a priority — that would make
    // routing order ambiguous (ORDER BY priority ASC, id ASC would just
    // fall back to id, silently overriding what the admin actually chose).
    // Disabled rows are exempt since they never reach the routing query at
    // all (select_and_reserve_gateway() filters on is_enabled = 1).
    if ($enabled) {
        if (isset($seenPriorities[$priority])) {
            json_response(false, null, "Priority {$priority} is used by more than one gateway — each enabled gateway needs a distinct priority.", 422);
        }
        $seenPriorities[$priority] = true;
    }
    $clean[$gatewayId] = ['priority' => $priority, 'enabled' => $enabled];
}

$pdo->beginTransaction();
try {
    // Full replace: this endpoint always receives the merchant's COMPLETE
    // desired assignment set (the admin UI is a checkbox list + priority
    // inputs saved all at once), so it's simplest and safest to reset and
    // rebuild rather than diff — no risk of a stale row surviving because
    // the client happened to omit it.
    $pdo->prepare('DELETE FROM merchant_gateway_assignments WHERE user_id = ?')->execute([$userId]);

    if ($clean) {
        $insert = $pdo->prepare(
            'INSERT INTO merchant_gateway_assignments (user_id, gateway_id, priority, is_enabled) VALUES (?, ?, ?, ?)'
        );
        foreach ($clean as $gatewayId => $row) {
            $insert->execute([$userId, $gatewayId, $row['priority'], $row['enabled'] ? 1 : 0]);
        }
    }

    write_audit_log(
        (int) $actor['id'],
        'merchant_gateways_updated',
        'user',
        $userId,
        ['assignments' => array_map(
            static fn (int $gatewayId, array $row): array => ['gateway_id' => $gatewayId] + $row,
            array_keys($clean),
            array_values($clean)
        )]
    );

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

json_response(true, null, "Gateway assignments saved for {$targetUser['name']}.");
