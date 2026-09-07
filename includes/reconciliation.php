<?php
/**
 * Resolves transactions stuck 'pending' with no webhook by polling the
 * gateway directly — see includes/gateway_providers/dispatch.php's
 * check_gateway_transaction_status(). Shares apply_transaction_outcome()
 * with the real webhook path (includes/gateway_webhooks.php), so a
 * reconciliation-resolved transaction is indistinguishable downstream
 * from a webhook-resolved one (same wallet update, same circuit-breaker
 * accounting, same customer webhook dispatch, same audit trail).
 *
 * Callable two ways: bin/reconcile-pending.php (cron) and
 * public/api/admin/reconciliation/run.php (admin "Run now" button) both
 * just call run_reconciliation() — this file has no CLI/HTTP awareness
 * of its own.
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/gateway_selector.php';
require_once __DIR__ . '/gateway_webhooks.php';
require_once __DIR__ . '/gateway_providers/dispatch.php';
require_once __DIR__ . '/gateway_providers/cashfree.php';
require_once __DIR__ . '/customer_webhooks.php';
require_once __DIR__ . '/chargeback_service.php';

const TRANSACTION_COLUMNS_FOR_RECONCILIATION = '
    id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, gateway_txn_id,
    merchant_order_id, end_customer_name, end_customer_email, end_customer_phone,
    beneficiary_name, beneficiary_account_number, beneficiary_ifsc, beneficiary_bank_name
';

/**
 * @param int $minPendingMinutes only consider transactions that have been
 *   pending at least this long — a payin created 10 seconds ago is still
 *   normally in flight, not stuck.
 * @return array{checked:int, resolved:int, still_pending:int, skipped_sandbox:int, errors:int}
 */
function run_reconciliation(PDO $pdo, int $minPendingMinutes = 5, int $limit = 50): array
{
    $stmt = $pdo->prepare(
        'SELECT ' . TRANSACTION_COLUMNS_FOR_RECONCILIATION . '
         FROM transactions
         WHERE status = "pending" AND gateway_id IS NOT NULL AND gateway_txn_id IS NOT NULL
           AND created_at <= (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
         ORDER BY created_at ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $minPendingMinutes, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $pending = $stmt->fetchAll();

    $results = ['checked' => 0, 'resolved' => 0, 'still_pending' => 0, 'skipped_sandbox' => 0, 'errors' => 0];

    foreach ($pending as $txn) {
        $results['checked']++;

        $gwStmt = $pdo->prepare('SELECT * FROM payment_gateways WHERE id = ?');
        $gwStmt->execute([$txn['gateway_id']]);
        $gateway = $gwStmt->fetch();

        if (!$gateway || !gateway_supports_live_order_creation($gateway)) {
            // Sandbox/unconfigured gateway — nothing real exists at a
            // provider to check against. Still record that we looked.
            $pdo->prepare('UPDATE transactions SET last_reconciled_at = UTC_TIMESTAMP(), reconciliation_attempts = reconciliation_attempts + 1 WHERE id = ?')
                ->execute([$txn['id']]);
            $results['skipped_sandbox']++;
            continue;
        }

        $status = check_gateway_transaction_status($gateway, $txn['type'], $txn['gateway_txn_id']);

        $pdo->prepare('UPDATE transactions SET last_reconciled_at = UTC_TIMESTAMP(), reconciliation_attempts = reconciliation_attempts + 1 WHERE id = ?')
            ->execute([$txn['id']]);

        if (!in_array($status, ['success', 'failed'], true)) {
            $results['still_pending']++;
            continue;
        }

        $pdo->beginTransaction();
        try {
            $lockStmt = $pdo->prepare(
                'SELECT ' . TRANSACTION_COLUMNS_FOR_RECONCILIATION . ' FROM transactions WHERE id = ? FOR UPDATE'
            );
            $lockStmt->execute([$txn['id']]);
            $locked = $lockStmt->fetch();

            if ($locked && $locked['status'] === 'pending') {
                apply_transaction_outcome($pdo, $locked, $status, null);
                write_audit_log(null, 'reconciliation_resolved', 'transaction', (int) $txn['id'], [
                    'outcome' => $status,
                    'gateway_id' => (int) $gateway['id'],
                ]);
                $pdo->commit();

                dispatch_customer_transaction_webhook($pdo, array_merge($locked, ['status' => $status]));
                $results['resolved']++;
            } else {
                // A webhook already resolved this transaction between our
                // initial SELECT and this lock — already handled, not an
                // error and not still pending, just not resolved BY us.
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[reconciliation] failed to apply outcome for transaction ' . $txn['id'] . ': ' . $e->getMessage());
            $results['errors']++;
        }
    }

    return $results;
}

/**
 * Chargeback reconciliation — a distinct pass from run_reconciliation()
 * above, deliberately never auto-applying anything. Unlike a pending
 * payment (a binary success/fail outcome reconciliation is safe to apply
 * directly), a chargeback discrepancy usually means either our copy is
 * stale (a webhook we never received) or the provider and platform simply
 * disagree — both cases the spec calls for surfacing to an admin, not
 * silently rewriting a financial record. See database/migration19.sql /
 * includes/chargeback_service.php for why the chargeback lifecycle exists
 * separately from the transaction state machine in the first place.
 *
 * Only re-checks chargebacks still in a non-terminal state (open/pending) —
 * won/lost/reversed/cancelled are resolved and not re-polled. Currently
 * only Cashfree has a status-fetch integration
 * (cashfree_fetch_dispute_status()); other providers are skipped, same
 * "never guess" contract as check_gateway_transaction_status().
 *
 * @return array{checked:int, matched:int, flagged:int, unknown:int}
 */
function reconcile_chargebacks(PDO $pdo, int $minAgeMinutes = 5, int $limit = 50): array
{
    $stmt = $pdo->prepare(
        "SELECT cb.id, cb.transaction_id, cb.gateway_id, cb.provider, cb.gateway_chargeback_id, cb.status, cb.total_amount
         FROM chargebacks cb
         WHERE cb.status IN ('open', 'pending')
           AND cb.created_at <= (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
         ORDER BY cb.created_at ASC
         LIMIT ?"
    );
    $stmt->bindValue(1, $minAgeMinutes, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $open = $stmt->fetchAll();

    $results = ['checked' => 0, 'matched' => 0, 'flagged' => 0, 'unknown' => 0];

    foreach ($open as $cb) {
        $results['checked']++;

        if ($cb['provider'] !== 'cashfree' || !$cb['gateway_id']) {
            $results['unknown']++;
            continue;
        }

        $gwStmt = $pdo->prepare('SELECT * FROM payment_gateways WHERE id = ?');
        $gwStmt->execute([$cb['gateway_id']]);
        $gateway = $gwStmt->fetch();
        if (!$gateway) {
            $results['unknown']++;
            continue;
        }

        $providerState = cashfree_fetch_dispute_status($gateway, $cb['gateway_chargeback_id']);
        if ($providerState === null) {
            $results['unknown']++;
            continue;
        }

        if ($providerState['normalized_status'] === $cb['status']) {
            $results['matched']++;
            continue;
        }

        // Discrepancy — our copy disagrees with the provider's current
        // status. Never auto-applied; flagged for admin review only. If
        // the transition IS actually valid, an admin can still let the
        // real webhook (a re-delivery, or the provider's own retry policy)
        // apply it normally through process_chargeback_event() — this pass
        // only ever records the finding.
        $pdo->prepare(
            "UPDATE chargebacks SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.reconciliation_flag', JSON_OBJECT('flagged_at', UTC_TIMESTAMP(), 'our_status', status, 'provider_status', ?)) WHERE id = ?"
        )->execute([$providerState['normalized_status'], $cb['id']]);

        write_audit_log(null, 'chargeback_reconciliation_discrepancy', 'chargeback', (int) $cb['id'], [
            'our_status' => $cb['status'],
            'provider_status' => $providerState['normalized_status'],
            'provider_status_raw' => $providerState['provider_status'],
        ]);

        $results['flagged']++;
    }

    return $results;
}
