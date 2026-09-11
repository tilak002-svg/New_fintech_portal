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
require_once __DIR__ . '/gateway_providers/dispatch.php';

// Circuit breaker: this many consecutive definite failures on a gateway
// (see record_gateway_outcome()) auto-excludes it from selection for the
// cooldown period below, without any admin action. Deliberately a fixed,
// explainable rule rather than anything adaptive — the trip and every
// recovery is written to audit_logs so it's never a silent behavior
// change an operator has to go digging for.
const GATEWAY_AUTO_PAUSE_FAILURE_THRESHOLD = 3;
const GATEWAY_AUTO_PAUSE_MINUTES = 15;

/**
 * Picks the highest-priority active gateway with enough remaining capacity
 * for $amount across every configured limit window, and atomically
 * reserves that capacity against it.
 *
 * Concurrency: for each candidate gateway, each applicable usage row
 * (daily/hourly/monthly) is created if missing (INSERT IGNORE) and then
 * locked with SELECT ... FOR UPDATE before its used_amount is read. Two
 * concurrent requests racing for the same gateway/window serialize on that
 * row lock — the second request only sees "remaining capacity" after the
 * first has committed its reservation, so no configured limit can ever be
 * oversubscribed.
 *
 * Order matters: every window is locked and checked BEFORE any of them is
 * written — a gateway is only ever reserved against once it's confirmed to
 * fit ALL applicable limits, never partially (e.g. incrementing daily usage
 * for a gateway that then turns out to be over its monthly limit).
 * per_transaction_limit_amount is checked first since it needs no lock —
 * cheapest rejection, done before touching the database at all.
 *
 * @param int $userId The merchant this selection is for — gates on
 *   merchant_gateway_assignments (a merchant only ever sees gateways an
 *   admin has explicitly assigned to them, ordered by that assignment's
 *   OWN priority, not the gateway's global default) and fails closed
 *   ('no_assigned_gateways') if the merchant has none.
 * @param string $direction 'payin' or 'payout' — gates on payin_enabled/
 *   payout_enabled and, for a non-mock gateway, on that direction's real
 *   provider credentials (see the is_mock/credential-completeness check
 *   in the loop below).
 * @return array{gateway: array|null, reason: string|null} reason is one of
 *   null (success), 'no_assigned_gateways' (merchant has no active
 *   gateway assignment at all), or 'no_eligible_gateway' (covers every
 *   per-candidate skip below: direction disabled, ticket size out of
 *   range, capacity exhausted, or a real-provider gateway with no usable
 *   credentials).
 */
function select_and_reserve_gateway(PDO $pdo, int $userId, string $amount, bool $sandboxOnly = false, string $direction = 'payin'): array
{
    // $sandboxOnly is additive and used only by the API docs' "Try it"
    // tester (see public/api/v1/try/*.php) — every existing caller omits
    // it and sees no behavior change. It exists because create_payin()/
    // create_payout() commit their own DB transaction and call the
    // provider only after commit, so gateway selection is the only safe
    // place to guarantee a test request can never reach a live gateway.
    $directionColumn = $direction === 'payout' ? 'payout_enabled' : 'payin_enabled';
    $gatewaysStmt = $pdo->prepare(
        "SELECT pg.id, pg.display_name, pg.provider, mga.priority, pg.daily_limit_amount, pg.hourly_limit_amount, pg.monthly_limit_amount, pg.per_transaction_limit_amount,
                pg.min_ticket_size, pg.max_ticket_size, pg.public_key, pg.api_key_encrypted, pg.payout_account_number, pg.sandbox_mode, pg.is_mock
         FROM payment_gateways pg
         INNER JOIN merchant_gateway_assignments mga ON mga.gateway_id = pg.id AND mga.user_id = ? AND mga.is_enabled = 1
         WHERE pg.status = \"active\"
           AND pg.{$directionColumn} = 1
           AND (pg.auto_paused_until IS NULL OR pg.auto_paused_until <= UTC_TIMESTAMP())"
        . ($sandboxOnly ? ' AND pg.sandbox_mode = 1' : '') .
        ' ORDER BY mga.priority ASC, pg.id ASC'
    );
    $gatewaysStmt->execute([$userId]);
    $gateways = $gatewaysStmt->fetchAll();

    if (!$gateways) {
        return ['gateway' => null, 'reason' => 'no_assigned_gateways'];
    }

    $today = gmdate('Y-m-d');
    $thisHour = gmdate('Y-m-d H:00:00');
    $thisMonth = gmdate('Y-m');

    $insertDaily = $pdo->prepare('INSERT IGNORE INTO gateway_daily_usage (gateway_id, usage_date, used_amount, transaction_count) VALUES (?, ?, 0.00, 0)');
    $lockDaily = $pdo->prepare('SELECT used_amount FROM gateway_daily_usage WHERE gateway_id = ? AND usage_date = ? FOR UPDATE');
    $reserveDaily = $pdo->prepare('UPDATE gateway_daily_usage SET used_amount = ?, transaction_count = transaction_count + 1 WHERE gateway_id = ? AND usage_date = ?');

    $insertHourly = $pdo->prepare('INSERT IGNORE INTO gateway_hourly_usage (gateway_id, usage_hour, used_amount, transaction_count) VALUES (?, ?, 0.00, 0)');
    $lockHourly = $pdo->prepare('SELECT used_amount FROM gateway_hourly_usage WHERE gateway_id = ? AND usage_hour = ? FOR UPDATE');
    $reserveHourly = $pdo->prepare('UPDATE gateway_hourly_usage SET used_amount = ?, transaction_count = transaction_count + 1 WHERE gateway_id = ? AND usage_hour = ?');

    $insertMonthly = $pdo->prepare('INSERT IGNORE INTO gateway_monthly_usage (gateway_id, usage_month, used_amount, transaction_count) VALUES (?, ?, 0.00, 0)');
    $lockMonthly = $pdo->prepare('SELECT used_amount FROM gateway_monthly_usage WHERE gateway_id = ? AND usage_month = ? FOR UPDATE');
    $reserveMonthly = $pdo->prepare('UPDATE gateway_monthly_usage SET used_amount = ?, transaction_count = transaction_count + 1 WHERE gateway_id = ? AND usage_month = ?');

    foreach ($gateways as $gateway) {
        $gatewayId = (int) $gateway['id'];

        if ($gateway['per_transaction_limit_amount'] !== null && money_cmp($amount, $gateway['per_transaction_limit_amount']) > 0) {
            continue;
        }
        // Ticket-size band — a distinct restriction from the hard ceiling
        // above (e.g. "this gateway only handles ₹100-₹25,000 transactions",
        // regardless of any per-transaction cap or remaining capacity).
        if ($gateway['min_ticket_size'] !== null && money_cmp($amount, $gateway['min_ticket_size']) < 0) {
            continue;
        }
        if ($gateway['max_ticket_size'] !== null && money_cmp($amount, $gateway['max_ticket_size']) > 0) {
            continue;
        }

        // A gateway not explicitly flagged as a mock/test gateway must
        // actually be callable for this direction — never silently treat
        // "missing/invalid credentials" as "simulate success". This is the
        // fix for the real bug where an incompletely-configured gateway
        // (e.g. a Razorpay row with no keys) would win the priority race
        // over a fully-configured Cashfree gateway and the caller would
        // then silently fall back to the local instant-success path.
        if (!$gateway['is_mock']) {
            $liveOk = $direction === 'payout'
                ? gateway_supports_live_payout($gateway)
                : gateway_supports_live_order_creation($gateway);
            if (!$liveOk) {
                continue;
            }
        }

        $insertDaily->execute([$gatewayId, $today]);
        $lockDaily->execute([$gatewayId, $today]);
        $projectedDaily = money_add($lockDaily->fetch()['used_amount'] ?? '0.00', $amount);
        if ($gateway['daily_limit_amount'] !== null && money_cmp($projectedDaily, $gateway['daily_limit_amount']) > 0) {
            continue;
        }

        $insertHourly->execute([$gatewayId, $thisHour]);
        $lockHourly->execute([$gatewayId, $thisHour]);
        $projectedHourly = money_add($lockHourly->fetch()['used_amount'] ?? '0.00', $amount);
        if ($gateway['hourly_limit_amount'] !== null && money_cmp($projectedHourly, $gateway['hourly_limit_amount']) > 0) {
            continue;
        }

        $insertMonthly->execute([$gatewayId, $thisMonth]);
        $lockMonthly->execute([$gatewayId, $thisMonth]);
        $projectedMonthly = money_add($lockMonthly->fetch()['used_amount'] ?? '0.00', $amount);
        if ($gateway['monthly_limit_amount'] !== null && money_cmp($projectedMonthly, $gateway['monthly_limit_amount']) > 0) {
            continue;
        }

        // Every applicable limit fits — commit all three reservations
        // together now that none of them can fail.
        $reserveDaily->execute([$projectedDaily, $gatewayId, $today]);
        $reserveHourly->execute([$projectedHourly, $gatewayId, $thisHour]);
        $reserveMonthly->execute([$projectedMonthly, $gatewayId, $thisMonth]);

        return ['gateway' => $gateway, 'reason' => null];
    }

    return ['gateway' => null, 'reason' => 'no_eligible_gateway'];
}

/**
 * Releases a reservation previously made by select_and_reserve_gateway()
 * across all three usage windows. Safe ONLY when the caller knows for
 * certain the reserved capacity was never actually used — e.g. a
 * synchronous, definite rejection from the provider within the same
 * request (see includes/payin_service.php's Razorpay handling). Never call
 * this for an ambiguous outcome (timeout) or from a later webhook — a
 * failure reported after the fact is handled by apply_transaction_outcome()
 * instead, which deliberately does NOT free the reservation, since "used"
 * here tracks attempts, not settlements (see includes/gateway_webhooks.php).
 *
 * Uses the CURRENT hour/month, which is correct because this is only ever
 * called synchronously within the same request that made the reservation
 * (same rule already documented above for the daily window).
 */
function release_gateway_reservation(PDO $pdo, int $gatewayId, string $amount): void
{
    $pdo->prepare(
        'UPDATE gateway_daily_usage
         SET used_amount = GREATEST(used_amount - ?, 0.00), transaction_count = GREATEST(transaction_count - 1, 0)
         WHERE gateway_id = ? AND usage_date = ?'
    )->execute([$amount, $gatewayId, gmdate('Y-m-d')]);

    $pdo->prepare(
        'UPDATE gateway_hourly_usage
         SET used_amount = GREATEST(used_amount - ?, 0.00), transaction_count = GREATEST(transaction_count - 1, 0)
         WHERE gateway_id = ? AND usage_hour = ?'
    )->execute([$amount, $gatewayId, gmdate('Y-m-d H:00:00')]);

    $pdo->prepare(
        'UPDATE gateway_monthly_usage
         SET used_amount = GREATEST(used_amount - ?, 0.00), transaction_count = GREATEST(transaction_count - 1, 0)
         WHERE gateway_id = ? AND usage_month = ?'
    )->execute([$amount, $gatewayId, gmdate('Y-m')]);
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

    $countStmt = $pdo->prepare('SELECT display_name, consecutive_failures FROM payment_gateways WHERE id = ?');
    $countStmt->execute([$gatewayId]);
    $gatewayRow = $countStmt->fetch();
    $failures = (int) ($gatewayRow['consecutive_failures'] ?? 0);

    if ($failures >= GATEWAY_AUTO_PAUSE_FAILURE_THRESHOLD) {
        $pdo->prepare(
            'UPDATE payment_gateways SET auto_paused_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? MINUTE) WHERE id = ?'
        )->execute([GATEWAY_AUTO_PAUSE_MINUTES, $gatewayId]);

        write_audit_log(null, 'gateway_auto_paused', 'payment_gateway', $gatewayId, [
            'consecutive_failures' => $failures,
            'pause_minutes' => GATEWAY_AUTO_PAUSE_MINUTES,
        ]);

        // notifications.message is VARCHAR(255) — kept name-free and short
        // (the title already carries the gateway name) so this never risks
        // truncation regardless of how long display_name is (max 80 chars).
        $gatewayName = $gatewayRow['display_name'] ?? "Gateway #{$gatewayId}";
        notify_admins(
            $pdo,
            'gateway',
            "{$gatewayName} auto-paused",
            "{$failures} consecutive transactions failed, so this gateway was automatically paused for " . GATEWAY_AUTO_PAUSE_MINUTES . " minutes and skipped during routing. Source: gateway health.",
            // No throttle needed on top of the failure-count gate itself —
            // consecutive_failures resets to 0 on the next success, so this
            // branch can't re-fire for the same gateway without a fresh
            // run of 3 failures first.
            0
        );
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

/** Same as gateway_daily_usage_snapshot(), for the current hour window. */
function gateway_hourly_usage_snapshot(PDO $pdo, int $gatewayId): array
{
    $stmt = $pdo->prepare(
        'SELECT used_amount, transaction_count FROM gateway_hourly_usage WHERE gateway_id = ? AND usage_hour = ?'
    );
    $stmt->execute([$gatewayId, gmdate('Y-m-d H:00:00')]);
    $row = $stmt->fetch();

    return [
        'used_amount' => $row['used_amount'] ?? '0.00',
        'transaction_count' => (int) ($row['transaction_count'] ?? 0),
    ];
}

/** Same as gateway_daily_usage_snapshot(), for the current calendar month. */
function gateway_monthly_usage_snapshot(PDO $pdo, int $gatewayId): array
{
    $stmt = $pdo->prepare(
        'SELECT used_amount, transaction_count FROM gateway_monthly_usage WHERE gateway_id = ? AND usage_month = ?'
    );
    $stmt->execute([$gatewayId, gmdate('Y-m')]);
    $row = $stmt->fetch();

    return [
        'used_amount' => $row['used_amount'] ?? '0.00',
        'transaction_count' => (int) ($row['transaction_count'] ?? 0),
    ];
}