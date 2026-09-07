<?php
/**
 * Chargeback / dispute lifecycle — a real PayIn (transactions.type='deposit')
 * can be disputed by the end customer's bank AFTER it already settled
 * SUCCESS. This is a SEPARATE lifecycle from the transaction state machine
 * (includes/gateway_webhooks.php) — a disputed transaction's own status
 * never changes; only the chargeback row's status does, and financial
 * impact is applied through wallet_ledger (immutable, insert-only) rather
 * than ever editing the original transaction or a past ledger row.
 *
 * Entry point: process_chargeback_event() — called by each provider's
 * webhook receiver (e.g. public/api/webhooks/cashfree.php) after that
 * provider's own signature verification. Idempotent at TWO levels:
 *   - chargeback_events.gateway_event_id (unique per provider) — the exact
 *     same webhook delivery repeated is a pure no-op, detected before
 *     anything else runs.
 *   - chargebacks.(provider, gateway_chargeback_id) — the dispute itself is
 *     upserted by this natural key, so "created" and "updated" events for
 *     the same dispute (in ANY order) converge on one row.
 * Out-of-order protection: an event whose provider-reported occurred_at is
 * OLDER than the chargeback's last_event_at is recorded (for the timeline)
 * but never allowed to move status or re-apply a financial impact.
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/customer_webhooks.php';

/** Every status a chargeback can occupy — see CHARGEBACK_TRANSITIONS. */
const CHARGEBACK_STATUSES = ['open', 'pending', 'won', 'lost', 'reversed', 'cancelled'];

/**
 * Guarded state machine, mirroring the transaction state machine's own
 * "recheck current status before every mutation" discipline
 * (includes/gateway_webhooks.php::apply_transaction_outcome()).
 *   open      -> pending, won, lost, cancelled
 *   pending   -> won, lost, cancelled
 *   won       -> reversed        (an earlier win later overturned to a loss)
 *   lost      -> reversed        (the debit is compensated back)
 *   reversed, cancelled -> (terminal; nothing transitions further)
 * A same-status "transition" (e.g. lost -> lost, a re-delivered event after
 * the status already applied) is always allowed as a no-op — handled
 * explicitly in process_chargeback_event() before financial impact is ever
 * considered.
 */
const CHARGEBACK_TRANSITIONS = [
    'open' => ['pending', 'won', 'lost', 'cancelled'],
    'pending' => ['won', 'lost', 'cancelled'],
    'won' => ['reversed'],
    'lost' => ['reversed'],
    'reversed' => [],
    'cancelled' => [],
];

function chargeback_transition_allowed(string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    return in_array($to, CHARGEBACK_TRANSITIONS[$from] ?? [], true);
}

/**
 * Main idempotent entry point for one inbound chargeback/dispute webhook
 * event. Never throws — every failure mode returns a safe {status,message}
 * a webhook receiver can respond with directly, same convention as
 * includes/gateway_webhooks.php::process_gateway_webhook().
 *
 * @param array $event {
 *   gateway_event_id: string (required, provider-unique per delivery),
 *   gateway_chargeback_id: string (required, provider-unique per dispute),
 *   event_type: string (raw provider event/webhook type, for the timeline),
 *   reference: ?string (the ORIGINAL transaction's reference/gateway_txn_id — how we find it),
 *   normalized_status: string (one of CHARGEBACK_STATUSES, already mapped by the caller from provider vocabulary),
 *   provider_status: ?string (raw provider status string, kept for reference),
 *   amount: ?string, fee: ?string (provider-reported; fee falls back to CHARGEBACK_DEFAULT_FEE),
 *   currency: ?string,
 *   reason_code: ?string, reason: ?string,
 *   occurred_at: ?string ('Y-m-d H:i:s' UTC — the provider's own event time; defaults to now if absent),
 *   due_at: ?string,
 *   raw: array (full original payload, stored for audit only),
 * }
 * @return array{status:int, message:string}
 */
function process_chargeback_event(PDO $pdo, int $gatewayId, string $provider, array $event): array
{
    $gatewayEventId = trim((string) ($event['gateway_event_id'] ?? ''));
    $disputeId = trim((string) ($event['gateway_chargeback_id'] ?? ''));
    $reference = trim((string) ($event['reference'] ?? ''));
    $normalizedStatus = $event['normalized_status'] ?? '';

    if ($gatewayEventId === '' || $disputeId === '' || !in_array($normalizedStatus, CHARGEBACK_STATUSES, true)) {
        return ['status' => 400, 'message' => 'Malformed chargeback event.'];
    }

    $occurredAt = $event['occurred_at'] ?? gmdate('Y-m-d H:i:s');
    $chargebackId = null;

    $pdo->beginTransaction();
    try {
        // Event-level idempotency FIRST — a duplicate delivery of the exact
        // same event stops here before touching the chargeback row at all.
        $insertEvent = $pdo->prepare(
            'INSERT IGNORE INTO chargeback_events (provider, gateway_chargeback_id, gateway_event_id, event_type, provider_status, normalized_status, payload, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertEvent->execute([
            $provider, $disputeId, $gatewayEventId, (string) ($event['event_type'] ?? 'unknown'),
            $event['provider_status'] ?? null, $normalizedStatus, json_encode($event['raw'] ?? []), $occurredAt,
        ]);
        if ($insertEvent->rowCount() === 0) {
            $pdo->commit();
            return ['status' => 200, 'message' => 'Duplicate chargeback event — already processed.'];
        }
        $eventId = (int) $pdo->lastInsertId();

        // Find (and lock) the existing chargeback by its natural key, or
        // create it fresh — this is what makes "updated before created"
        // (or any other ordering) converge correctly: whichever event
        // arrives FIRST for a given dispute creates the row.
        $cbStmt = $pdo->prepare('SELECT * FROM chargebacks WHERE provider = ? AND gateway_chargeback_id = ? FOR UPDATE');
        $cbStmt->execute([$provider, $disputeId]);
        $chargeback = $cbStmt->fetch();
        $justCreated = false;

        if (!$chargeback) {
            // First time this dispute has ever been seen — locate the
            // original transaction it references. Tries `reference`
            // (Verapay's own reference) first, then gateway_txn_id, mirroring
            // process_gateway_webhook()'s own correlation order.
            $transaction = null;
            if ($reference !== '') {
                $txnStmt = $pdo->prepare('SELECT id, user_id FROM transactions WHERE reference = ? OR gateway_txn_id = ? LIMIT 1');
                $txnStmt->execute([$reference, $reference]);
                $transaction = $txnStmt->fetch();
            }

            if (!$transaction) {
                $pdo->prepare('UPDATE chargeback_events SET skip_reason = ? WHERE id = ?')
                    ->execute(['no_matching_transaction', $eventId]);
                write_audit_log(null, 'chargeback_unmatched_transaction', 'payment_gateway', $gatewayId, ['reference' => $reference, 'dispute_id' => $disputeId]);
                $pdo->commit();
                return ['status' => 200, 'message' => 'No matching transaction for this dispute — recorded, not applied.'];
            }

            $amount = $event['amount'] !== null ? sanitize_amount($event['amount']) : null;
            if ($amount === null) {
                // No provider-reported amount — fall back to the original
                // transaction's own amount, never zero.
                $amtStmt = $pdo->prepare('SELECT amount FROM transactions WHERE id = ?');
                $amtStmt->execute([$transaction['id']]);
                $amount = $amtStmt->fetchColumn();
            }
            $fee = $event['fee'] !== null ? sanitize_amount($event['fee']) : null;
            if ($fee === null) {
                $fee = calculate_fee('chargeback', $provider, $amount);
            }
            $total = money_add($amount, $fee);

            $insertCb = $pdo->prepare(
                'INSERT INTO chargebacks (transaction_id, user_id, gateway_id, provider, gateway_chargeback_id, gateway_reference, amount, fee, total_amount, currency, reason_code, reason, status, provider_status, last_event_at, initiated_at, due_at, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "open", ?, ?, ?, ?, ?)'
            );
            $insertCb->execute([
                $transaction['id'], $transaction['user_id'], $gatewayId, $provider, $disputeId, $reference ?: null,
                $amount, $fee, $total, $event['currency'] ?? 'INR', $event['reason_code'] ?? null, $event['reason'] ?? null,
                $event['provider_status'] ?? null, $occurredAt, $event['initiated_at'] ?? gmdate('Y-m-d H:i:s'), $event['due_at'] ?? null,
                json_encode(['created_from_event' => $event['event_type'] ?? 'unknown']),
            ]);
            $chargebackId = (int) $pdo->lastInsertId();
            $justCreated = true;

            $cbStmt->execute([$provider, $disputeId]);
            $chargeback = $cbStmt->fetch();

            write_audit_log(null, 'chargeback_created', 'chargeback', $chargebackId, ['transaction_id' => $transaction['id'], 'dispute_id' => $disputeId, 'amount' => $amount, 'fee' => $fee]);
        }

        $chargebackId = (int) $chargeback['id'];
        $pdo->prepare('UPDATE chargeback_events SET chargeback_id = ? WHERE id = ?')->execute([$chargebackId, $eventId]);

        // The event that just created the row already carries status
        // 'open' in the DB — nothing further to transition for THIS event
        // unless it reports a status beyond 'open' (e.g. the very first
        // event we ever see for a dispute already says "lost").
        if ($justCreated && $normalizedStatus === 'open') {
            $pdo->prepare('UPDATE chargeback_events SET applied = 1 WHERE id = ?')->execute([$eventId]);
            $pdo->commit();
            dispatch_customer_chargeback_webhook($pdo, $chargeback, 'chargeback.created');
            write_audit_log(null, 'chargeback_status_updated', 'chargeback', $chargebackId, ['from' => null, 'to' => 'open', 'dispute_id' => $disputeId]);
            return ['status' => 200, 'message' => "Chargeback {$disputeId} recorded."];
        }

        // Out-of-order guard — an event older than what's already applied
        // is recorded (the chargeback_events row above already persists it)
        // but never allowed to move status.
        if (!$justCreated && $chargeback['last_event_at'] !== null && $occurredAt < $chargeback['last_event_at']) {
            $pdo->prepare('UPDATE chargeback_events SET skip_reason = ? WHERE id = ?')
                ->execute(['out_of_order', $eventId]);
            write_audit_log(null, 'chargeback_event_out_of_order', 'chargeback', $chargebackId, ['event_occurred_at' => $occurredAt, 'current_last_event_at' => $chargeback['last_event_at']]);
            $pdo->commit();
            return ['status' => 200, 'message' => 'Out-of-order event — recorded, not applied.'];
        }

        $fromStatus = $chargeback['status'];

        if (!chargeback_transition_allowed($fromStatus, $normalizedStatus)) {
            $pdo->prepare('UPDATE chargeback_events SET skip_reason = ? WHERE id = ?')
                ->execute(["invalid_transition:{$fromStatus}->{$normalizedStatus}", $eventId]);
            write_audit_log(null, 'chargeback_invalid_transition', 'chargeback', $chargebackId, ['from' => $fromStatus, 'to' => $normalizedStatus]);
            $pdo->commit();
            return ['status' => 200, 'message' => 'Invalid status transition — ignored.'];
        }

        if ($normalizedStatus === $fromStatus) {
            // Same-status re-delivery (e.g. a duplicate LOST after LOST
            // already applied, but under a genuinely new event id — the
            // EVENT wasn't a dupe, but the outcome is). Never re-apply
            // financial impact.
            $pdo->prepare('UPDATE chargebacks SET last_event_at = ?, provider_status = COALESCE(?, provider_status) WHERE id = ?')
                ->execute([$occurredAt, $event['provider_status'] ?? null, $chargebackId]);
            $pdo->prepare('UPDATE chargeback_events SET applied = 1 WHERE id = ?')->execute([$eventId]);
            $pdo->commit();
            return ['status' => 200, 'message' => 'No status change — recorded.'];
        }

        $resolvedAt = in_array($normalizedStatus, ['won', 'lost', 'reversed', 'cancelled'], true) ? gmdate('Y-m-d H:i:s') : null;
        $pdo->prepare(
            'UPDATE chargebacks SET status = ?, provider_status = COALESCE(?, provider_status), last_event_at = ?, resolved_at = COALESCE(resolved_at, ?), resolution = COALESCE(?, resolution) WHERE id = ?'
        )->execute([$normalizedStatus, $event['provider_status'] ?? null, $occurredAt, $resolvedAt, $event['reason'] ?? null, $chargebackId]);

        // Financial impact: applied exactly once, the moment status becomes
        // 'lost' — never re-applied on a later same-status event (guarded
        // above) and never applied for 'won'/'cancelled'.
        if ($normalizedStatus === 'lost' && $chargeback['financial_impact_applied_at'] === null) {
            apply_chargeback_financial_impact($pdo, $chargeback);
            $pdo->prepare('UPDATE chargebacks SET financial_impact_applied_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $chargebackId]);
        } elseif ($normalizedStatus === 'reversed' && $chargeback['financial_impact_applied_at'] !== null) {
            // Reversing a chargeback whose debit was already applied (it
            // had reached 'lost') — compensate it back with a new,
            // opposite-signed ledger row. Never edits the original debit row.
            reverse_chargeback_financial_impact($pdo, $chargeback);
        } elseif ($normalizedStatus === 'reversed' && $chargeback['financial_impact_applied_at'] === null) {
            // won -> reversed: the earlier WIN itself is overturned to a
            // loss, so this is a fresh debit, not a reversal of a debit
            // that never happened.
            apply_chargeback_financial_impact($pdo, $chargeback);
            $pdo->prepare('UPDATE chargebacks SET financial_impact_applied_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s'), $chargebackId]);
        }

        $pdo->prepare('UPDATE chargeback_events SET applied = 1 WHERE id = ?')->execute([$eventId]);
        write_audit_log(null, 'chargeback_status_updated', 'chargeback', $chargebackId, ['from' => $fromStatus, 'to' => $normalizedStatus, 'dispute_id' => $disputeId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[chargeback_service] ' . $e->getMessage());
        return ['status' => 500, 'message' => 'Unable to process this chargeback event right now.'];
    }

    // Refetch full row for the callback payload — never call out to a
    // customer's server while holding any lock, same rule every other
    // outbound call in this codebase follows.
    $freshStmt = $pdo->prepare('SELECT * FROM chargebacks WHERE id = ?');
    $freshStmt->execute([$chargebackId]);
    $fresh = $freshStmt->fetch();
    if ($fresh) {
        dispatch_customer_chargeback_webhook($pdo, $fresh, $justCreated ? 'chargeback.created' : 'chargeback.updated');
    }

    return ['status' => 200, 'message' => "Chargeback {$disputeId} marked {$normalizedStatus}."];
}

/**
 * Debits total_amount from the merchant's wallet — available_balance first,
 * any shortfall becomes receivable_balance (see database/migration19.sql).
 * Caller must already hold the chargebacks row lock (FOR UPDATE) — this
 * additionally locks the wallet row before touching it, same discipline as
 * includes/gateway_webhooks.php::apply_transaction_outcome().
 */
function apply_chargeback_financial_impact(PDO $pdo, array $chargeback): void
{
    $userId = (int) $chargeback['user_id'];
    $impact = $chargeback['total_amount'];

    $pdo->prepare('INSERT IGNORE INTO wallets (user_id, available_balance, pending_balance, receivable_balance, currency) VALUES (?, 0.00, 0.00, 0.00, "INR")')
        ->execute([$userId]);

    $walletStmt = $pdo->prepare('SELECT available_balance, receivable_balance FROM wallets WHERE user_id = ? FOR UPDATE');
    $walletStmt->execute([$userId]);
    $wallet = $walletStmt->fetch();
    $available = $wallet['available_balance'] ?? '0.00';
    $receivable = $wallet['receivable_balance'] ?? '0.00';

    $fromAvailable = money_cmp($available, $impact) < 0 ? $available : $impact;
    $shortfall = money_sub($impact, $fromAvailable);

    $newAvailable = money_sub($available, $fromAvailable);
    $newReceivable = money_add($receivable, $shortfall);

    $pdo->prepare('UPDATE wallets SET available_balance = ?, receivable_balance = ? WHERE user_id = ?')
        ->execute([$newAvailable, $newReceivable, $userId]);

    $pdo->prepare(
        'INSERT INTO wallet_ledger (user_id, entry_type, reference_type, reference_id, amount, available_balance_after, receivable_balance_after, currency, description)
         VALUES (?, "chargeback_debit", "chargeback", ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId, (int) $chargeback['id'], money_mul($impact, '-1'), $newAvailable, $newReceivable, $chargeback['currency'] ?? 'INR',
        "Chargeback {$chargeback['gateway_chargeback_id']} — amount " . money_format($chargeback['amount']) . ' + fee ' . money_format($chargeback['fee']),
    ]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, "chargeback", ?, ?)')
        ->execute([
            $userId,
            'Chargeback debited',
            'A chargeback of ' . money_format($impact) . ' has been debited from your wallet.'
                . (money_cmp($shortfall, '0.00') > 0 ? ' ' . money_format($shortfall) . ' is outstanding as a receivable.' : ''),
        ]);
}

/**
 * Compensating entry for a chargeback whose debit had already been applied
 * and is now overturned (status -> reversed). Reduces receivable_balance
 * first (that's the "you owed us" portion), any remainder credited back to
 * available_balance — the exact inverse order of how the debit was applied.
 * Never edits the original debit's ledger row; this is always a NEW row.
 */
function reverse_chargeback_financial_impact(PDO $pdo, array $chargeback): void
{
    $userId = (int) $chargeback['user_id'];
    $impact = $chargeback['total_amount'];

    $walletStmt = $pdo->prepare('SELECT available_balance, receivable_balance FROM wallets WHERE user_id = ? FOR UPDATE');
    $walletStmt->execute([$userId]);
    $wallet = $walletStmt->fetch();
    $available = $wallet['available_balance'] ?? '0.00';
    $receivable = $wallet['receivable_balance'] ?? '0.00';

    $fromReceivable = money_cmp($receivable, $impact) < 0 ? $receivable : $impact;
    $remainder = money_sub($impact, $fromReceivable);

    $newReceivable = money_sub($receivable, $fromReceivable);
    $newAvailable = money_add($available, $remainder);

    $pdo->prepare('UPDATE wallets SET available_balance = ?, receivable_balance = ? WHERE user_id = ?')
        ->execute([$newAvailable, $newReceivable, $userId]);

    $pdo->prepare(
        'INSERT INTO wallet_ledger (user_id, entry_type, reference_type, reference_id, amount, available_balance_after, receivable_balance_after, currency, description)
         VALUES (?, "chargeback_reversal", "chargeback", ?, ?, ?, ?, ?, ?)'
    )->execute([
        $userId, (int) $chargeback['id'], $impact, $newAvailable, $newReceivable, $chargeback['currency'] ?? 'INR',
        "Chargeback {$chargeback['gateway_chargeback_id']} reversed — prior debit compensated",
    ]);

    $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, "chargeback", ?, ?)')
        ->execute([$userId, 'Chargeback reversed', 'A previous chargeback debit of ' . money_format($impact) . ' has been reversed and credited back.']);
}
