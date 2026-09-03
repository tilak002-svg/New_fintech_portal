<?php
/**
 * Our-side gateway selection and daily capacity reservation.
 *
 * select_and_reserve_gateway() must be called from inside a PDO
 * transaction the caller already opened (as deposits/create.php and
 * withdrawals/create.php already do around their own transaction insert).
 * It does not begin/commit its own transaction: the row lock it takes on
 * gateway_daily_usage has to be released by the *same* commit/rollback
 * that decides whether the transaction row is actually created, otherwise
 * a reservation could be kept for a payment that never got created (or
 * vice versa).
 */

require_once __DIR__ . '/money.php';

// Circuit breaker: this many consecutive definite failures on a gateway
// (see record_gateway_outcome()) auto-excludes it from selection for the
// cooldown period below, without any admin action. Deliberately a fixed,
// explainable rule rather than anything adaptive — the trip and every
// recovery is written to audit_logs so it's never a silent behavior
// change an operator has to go digging for.
const GATEWAY_AUTO_PAUSE_FAILURE_THRESHOLD = 3;
const GATEWAY_AUTO_PAUSE_MINUTES = 15;

/**
 * Picks the highest-priority active gateway with enough remaining daily
 * capacity for $amount, and atomically reserves that capacity against it.
 *
 * Concurrency: for each candidate gateway, the per-day usage row is
 * created if missing (INSERT IGNORE) and then locked with SELECT ... FOR
 * UPDATE before its used_amount is read. Two concurrent requests racing
 * for the same gateway/day serialize on that row lock — the second request
 * only sees "remaining capacity" after the first has committed its
 * reservation, so the configured daily_limit_amount can never be
 * oversubscribed.
 *
 * @return array{gateway: array|null, reason: string|null} reason is one of
 *   null (success), 'no_active_gateways', or 'all_gateways_at_limit'.
 */
function select_and_reserve_gateway(PDO $pdo, string $amount): array
{
    $gatewaysStmt = $pdo->prepare(
        'SELECT id, display_name, provider, priority, daily_limit_amount, public_key, api_key_encrypted, payout_account_number, sandbox_mode
         FROM payment_gateways
         WHERE status = "active"
           AND (auto_paused_until IS NULL OR auto_paused_until <= UTC_TIMESTAMP())
         ORDER BY priority ASC, id ASC'
    );
    $gatewaysStmt->execute();
    $gateways = $gatewaysStmt->fetchAll();

    if (!$gateways) {
        return ['gateway' => null, 'reason' => 'no_active_gateways'];
    }

    $today = gmdate('Y-m-d');

    $insertUsage = $pdo->prepare(
        'INSERT IGNORE INTO gateway_daily_usage (gateway_id, usage_date, used_amount, transaction_count)
         VALUES (?, ?, 0.00, 0)'
    );
    $lockUsage = $pdo->prepare(
        'SELECT used_amount FROM gateway_daily_usage WHERE gateway_id = ? AND usage_date = ? FOR UPDATE'
    );
    $reserveUsage = $pdo->prepare(
        'UPDATE gateway_daily_usage SET used_amount = ?, transaction_count = transaction_count + 1
         WHERE gateway_id = ? AND usage_date = ?'
    );

    foreach ($gateways as $gateway) {
        $gatewayId = (int) $gateway['id'];

        $insertUsage->execute([$gatewayId, $today]);
        $lockUsage->execute([$gatewayId, $today]);
        $usageRow = $lockUsage->fetch();
        $used = $usageRow['used_amount'] ?? '0.00';

        $limit = $gateway['daily_limit_amount'];
        $projected = money_add($used, $amount);

        if ($limit !== null && money_cmp($projected, $limit) > 0) {
            continue;
        }

        $reserveUsage->execute([$projected, $gatewayId, $today]);

        return ['gateway' => $gateway, 'reason' => null];
    }

    return ['gateway' => null, 'reason' => 'all_gateways_at_limit'];
}

/**
 * Releases a same-day reservation previously made by
 * select_and_reserve_gateway(). Safe ONLY when the caller knows for
 * certain the reserved capacity was never actually used — e.g. a
 * synchronous, definite rejection from the provider within the same
 * request (see /api/deposits/create.php's Razorpay handling). Never call
 * this for an ambiguous outcome (timeout) or from a later webhook — a
 * failure reported after the fact is handled by apply_transaction_outcome()
 * instead, which deliberately does NOT free the reservation, since "used"
 * here tracks attempts, not settlements (see includes/gateway_webhooks.php).
 */
function release_gateway_reservation(PDO $pdo, int $gatewayId, string $amount): void
{
    $pdo->prepare(
        'UPDATE gateway_daily_usage
         SET used_amount = GREATEST(used_amount - ?, 0.00), transaction_count = GREATEST(transaction_count - 1, 0)
         WHERE gateway_id = ? AND usage_date = ?'
    )->execute([$amount, $gatewayId, gmdate('Y-m-d')]);
}

/**
 * Records a definite (never ambiguous) transaction outcome against the
 * gateway that handled it, and trips or clears the circuit breaker.
 *
 * Called from exactly one place, apply_transaction_outcome() in
 * gateway_webhooks.php — which itself is only ever reached for a
 * confirmed webhook result or a synchronous, definite provider rejection
 * (see RazorpayAmbiguousException handling in deposits/create.php). An
 * unknown/timeout outcome never reaches here, so it can never contribute
 * to a pause — exactly the FR-010 distinction this whole system is built
 * around.
 */
function record_gateway_outcome(PDO $pdo, int $gatewayId, bool $success): void
{
    if ($success) {
        $pdo->prepare('UPDATE payment_gateways SET consecutive_failures = 0, auto_paused_until = NULL WHERE id = ?')
            ->execute([$gatewayId]);
        return;
    }

    $pdo->prepare('UPDATE payment_gateways SET consecutive_failures = consecutive_failures + 1 WHERE id = ?')
        ->execute([$gatewayId]);

    $countStmt = $pdo->prepare('SELECT consecutive_failures FROM payment_gateways WHERE id = ?');
    $countStmt->execute([$gatewayId]);
    $failures = (int) $countStmt->fetchColumn();

    if ($failures >= GATEWAY_AUTO_PAUSE_FAILURE_THRESHOLD) {
        $pdo->prepare(
            'UPDATE payment_gateways SET auto_paused_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?'
        )->execute([GATEWAY_AUTO_PAUSE_MINUTES, $gatewayId]);

        write_audit_log(null, 'gateway_auto_paused', 'payment_gateway', $gatewayId, [
            'consecutive_failures' => $failures,
            'pause_minutes' => GATEWAY_AUTO_PAUSE_MINUTES,
        ]);
    }
}

/**
 * Read-only usage snapshot for the admin gateway list — no locking, since
 * nothing here reserves capacity.
 */
function gateway_daily_usage_snapshot(PDO $pdo, int $gatewayId): array
{
    $stmt = $pdo->prepare(
        'SELECT used_amount, transaction_count FROM gateway_daily_usage WHERE gateway_id = ? AND usage_date = ?'
    );
    $stmt->execute([$gatewayId, gmdate('Y-m-d')]);
    $row = $stmt->fetch();

    return [
        'used_amount' => $row['used_amount'] ?? '0.00',
        'transaction_count' => (int) ($row['transaction_count'] ?? 0),
    ];
}