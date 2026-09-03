<?php
/**
 * Inbound gateway webhook processing.
 *
 * IMPORTANT — provider-specific pieces are placeholders:
 * verify_generic_webhook_signature() implements one common scheme (raw
 * body HMAC-SHA256, hex-encoded, in a single header). Real providers
 * differ — Razorpay signs the raw body with HMAC-SHA256 hex in
 * X-Razorpay-Signature; Stripe signs a timestamp+body string with a
 * versioned scheme in Stripe-Signature; PayU posts form-encoded fields
 * with a provider-specific hash formula instead of a header at all. The
 * expected payload shape here (event_id/reference/gateway_txn_id/status)
 * is likewise generic. Both MUST be replaced with the real provider's
 * scheme — confirmed from that provider's docs and live account — before
 * any gateway here is used for real traffic. Nothing about the
 * idempotency, transaction-resolution, or wallet-crediting logic below is
 * a placeholder; that part is provider-agnostic and safe to rely on as-is.
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/gateway_selector.php';
require_once __DIR__ . '/customer_webhooks.php';

function verify_generic_webhook_signature(string $rawBody, string $signatureHeader, string $secret): bool
{
    if ($signatureHeader === '' || $secret === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, strtolower(trim($signatureHeader)));
}

/**
 * Idempotently applies one webhook delivery.
 *
 * Correlates the delivery back to a transaction by `reference` when
 * present, otherwise by `gateway_txn_id` — Razorpay's integration uses the
 * latter since it isn't confirmed to echo our reference back (see
 * includes/gateway_providers/razorpay.php); a provider that does confirm
 * merchant-reference echoing can keep using `reference` as the generic
 * receiver already does.
 *
 * @return array{status:int, message:string} an HTTP status + message the
 *   caller can respond with directly. Duplicate deliveries, deliveries for
 *   an unknown/already-resolved transaction, and unrecognized event
 *   statuses all return 200 (no error) so the provider doesn't retry
 *   something that will never succeed — full detail lands in webhook_events
 *   and audit_logs either way.
 */
function process_gateway_webhook(PDO $pdo, int $gatewayId, array $payload): array
{
    $eventId = trim((string) ($payload['event_id'] ?? ''));
    $reference = trim((string) ($payload['reference'] ?? ''));
    $gatewayTxnId = trim((string) ($payload['gateway_txn_id'] ?? ''));
    $status = $payload['status'] ?? '';

    if ($eventId === '' || ($reference === '' && $gatewayTxnId === '')) {
        return ['status' => 400, 'message' => 'event_id and either reference or gateway_txn_id are required.'];
    }

    $pdo->beginTransaction();
    try {
        // INSERT IGNORE + rowCount is the same lazily-create-and-detect
        // pattern the gateway usage reservation uses: the UNIQUE KEY on
        // (gateway_id, event_id) is what actually enforces "process this
        // exact delivery at most once" — a re-delivered webhook hits the
        // duplicate key, rowCount() is 0, and we stop before touching the
        // transaction or wallet a second time.
        $insertEvent = $pdo->prepare(
            'INSERT IGNORE INTO webhook_events (gateway_id, event_id, gateway_txn_id, payload, signature_valid, status)
             VALUES (?, ?, ?, ?, 1, "received")'
        );
        $insertEvent->execute([$gatewayId, $eventId, $gatewayTxnId ?: null, json_encode($payload)]);

        if ($insertEvent->rowCount() === 0) {
            $pdo->commit();
            return ['status' => 200, 'message' => 'Duplicate delivery — already processed.'];
        }

        if ($reference !== '') {
            $txnStmt = $pdo->prepare(
                'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id
                 FROM transactions WHERE reference = ? FOR UPDATE'
            );
            $txnStmt->execute([$reference]);
        } else {
            $txnStmt = $pdo->prepare(
                'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id
                 FROM transactions WHERE gateway_txn_id = ? AND gateway_id = ? FOR UPDATE'
            );
            $txnStmt->execute([$gatewayTxnId, $gatewayId]);
        }
        $transaction = $txnStmt->fetch();

        if (!$transaction || (int) $transaction['gateway_id'] !== $gatewayId) {
            mark_webhook_event($pdo, $gatewayId, $eventId, 'ignored');
            write_audit_log(null, 'webhook_unmatched_transaction', 'payment_gateway', $gatewayId, ['reference' => $reference, 'event_id' => $eventId]);
            $pdo->commit();
            return ['status' => 200, 'message' => 'No matching pending transaction for this gateway — ignored.'];
        }

        if (!in_array($status, ['success', 'failed'], true)) {
            mark_webhook_event($pdo, $gatewayId, $eventId, 'ignored');
            $pdo->commit();
            return ['status' => 200, 'message' => 'Event status not actionable — ignored.'];
        }

        if ($transaction['status'] !== 'pending') {
            mark_webhook_event($pdo, $gatewayId, $eventId, 'ignored');
            $pdo->commit();
            return ['status' => 200, 'message' => 'Transaction already resolved — ignored.'];
        }

        apply_transaction_outcome($pdo, $transaction, $status, $gatewayTxnId ?: null);
        mark_webhook_event($pdo, $gatewayId, $eventId, 'processed');

        write_audit_log(null, 'webhook_processed', 'transaction', (int) $transaction['id'], [
            'gateway_id' => $gatewayId,
            'event_id' => $eventId,
            'outcome' => $status,
        ]);

        $pdo->commit();

        // Never call out to a customer's server while holding the
        // transaction/wallet row locks above — same rule
        // deposit_service.php follows for its own outbound calls.
        dispatch_customer_transaction_webhook($pdo, array_merge($transaction, ['status' => $status]));

        return ['status' => 200, 'message' => "Transaction marked {$status}."];
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[gateway_webhooks] ' . $e->getMessage());
        return ['status' => 500, 'message' => 'Unable to process this webhook right now.'];
    }
}

function mark_webhook_event(PDO $pdo, int $gatewayId, string $eventId, string $status): void
{
    $pdo->prepare('UPDATE webhook_events SET status = ?, processed_at = NOW() WHERE gateway_id = ? AND event_id = ?')
        ->execute([$status, $gatewayId, $eventId]);
}

/**
 * Moves a pending deposit/withdrawal to its final state and settles the
 * wallet hold that was placed at creation time. Caller must already hold
 * the transaction row lock (FOR UPDATE) before calling this.
 */
function apply_transaction_outcome(PDO $pdo, array $transaction, string $outcome, ?string $gatewayTxnId): void
{
    $walletLock = $pdo->prepare('SELECT 1 FROM wallets WHERE user_id = ? FOR UPDATE');
    $walletLock->execute([$transaction['user_id']]);

    if ($transaction['type'] === 'deposit') {
        // Bank-transfer deposits hold net_amount in pending_balance from
        // creation (see /api/deposits/create.php) — success moves it into
        // available_balance, failure just releases the hold.
        $held = $transaction['net_amount'];
        if ($outcome === 'success') {
            $pdo->prepare('UPDATE wallets SET pending_balance = pending_balance - ?, available_balance = available_balance + ? WHERE user_id = ?')
                ->execute([$held, $held, $transaction['user_id']]);
        } else {
            $pdo->prepare('UPDATE wallets SET pending_balance = pending_balance - ? WHERE user_id = ?')
                ->execute([$held, $transaction['user_id']]);
        }
    } else {
        // Withdrawals hold amount+fee in pending_balance from creation
        // (see /api/withdrawals/create.php) — success means it's gone for
        // good, failure refunds the hold back to available_balance.
        $held = money_add($transaction['amount'], $transaction['fee']);
        if ($outcome === 'success') {
            $pdo->prepare('UPDATE wallets SET pending_balance = pending_balance - ? WHERE user_id = ?')
                ->execute([$held, $transaction['user_id']]);
        } else {
            $pdo->prepare('UPDATE wallets SET pending_balance = pending_balance - ?, available_balance = available_balance + ? WHERE user_id = ?')
                ->execute([$held, $held, $transaction['user_id']]);
        }
    }

    $pdo->prepare('UPDATE transactions SET status = ?, gateway_txn_id = COALESCE(?, gateway_txn_id) WHERE id = ?')
        ->execute([$outcome, $gatewayTxnId, $transaction['id']]);

    if (!empty($transaction['gateway_id'])) {
        record_gateway_outcome($pdo, (int) $transaction['gateway_id'], $outcome === 'success');
    }

    $title = $transaction['type'] === 'deposit' ? 'Deposit' : 'Withdrawal';
    $message = $outcome === 'success'
        ? "Your " . strtolower($title) . " has settled successfully."
        : "Your " . strtolower($title) . " could not be completed and any held funds have been released back to your available balance.";
    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, ?, ?, ?)')
        ->execute([$transaction['user_id'], strtolower($transaction['type']), "{$title} " . ucfirst($outcome), $message]);
}