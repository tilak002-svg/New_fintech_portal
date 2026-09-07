<?php
/**
 * Outbound delivery of transaction settlement events to a customer's own
 * payin_callback_url / payout_callback_url (Settings -> API access).
 *
 * Queued with retry/backoff (customer_webhook_deliveries): the first
 * attempt still happens synchronously right after the DB transaction that
 * settled the underlying transaction has already committed — never while
 * holding any row lock (same rule payin_service.php/payout_service.php
 * already follow for outbound gateway calls) — so the common case (2xx on
 * the first try) has the same latency it always did. A failed attempt is
 * rescheduled instead of given up on; process_webhook_retry_queue() (run
 * by bin/process-webhook-retries.php on a cron, or an admin's "Retry now")
 * picks it back up once next_attempt_at arrives.
 *
 * Silently does nothing if the customer never configured a callback URL
 * for this transaction's direction, or never has a signing secret yet
 * (issued lazily the first time they load Settings -> API access — see
 * public/api/settings/api-credentials.php) — both are normal, not errors.
 */

require_once __DIR__ . '/gateway_secrets.php';
require_once __DIR__ . '/functions.php';

/** Minutes to wait before each subsequent retry, indexed by (attempts - 1). */
const WEBHOOK_RETRY_BACKOFF_MINUTES = [1, 5, 30, 120, 360];

/**
 * @param array $transaction must have: id, user_id, type ('deposit'|'withdrawal'),
 *   status (the NEW/final status — 'success' or 'failed'), reference, amount,
 *   fee, net_amount, currency.
 */
function dispatch_customer_transaction_webhook(PDO $pdo, array $transaction): void
{
    $credStmt = $pdo->prepare(
        'SELECT payin_callback_url, payout_callback_url, webhook_signing_secret_encrypted
         FROM customer_api_credentials WHERE user_id = ?'
    );
    $credStmt->execute([$transaction['user_id']]);
    $creds = $credStmt->fetch();
    if (!$creds) {
        return;
    }

    $url = $transaction['type'] === 'deposit' ? $creds['payin_callback_url'] : $creds['payout_callback_url'];
    if (!$url || !$creds['webhook_signing_secret_encrypted']) {
        return;
    }

    // 'payin'/'payout' vocabulary, not the internal 'deposit'/'withdrawal'
    // enum value — see includes/functions.php::transaction_type_public_name().
    $publicType = transaction_type_public_name($transaction['type']);
    $event = $publicType . '.' . $transaction['status'];

    $payload = [
        'event' => $event,
        'reference' => $transaction['reference'],
        'type' => $publicType,
        'status' => $transaction['status'],
        'amount' => $transaction['amount'],
        'fee' => $transaction['fee'],
        'net_amount' => $transaction['net_amount'],
        'currency' => $transaction['currency'] ?? 'INR',
        'merchant_order_id' => $transaction['merchant_order_id'] ?? null,
        'occurred_at' => gmdate('c'),
    ];

    if ($transaction['type'] === 'deposit' && !empty($transaction['end_customer_name'])) {
        $payload['end_customer'] = [
            'name' => $transaction['end_customer_name'],
            'email' => $transaction['end_customer_email'] ?? null,
            'phone' => $transaction['end_customer_phone'] ?? null,
        ];
    }
    if ($transaction['type'] === 'withdrawal' && !empty($transaction['beneficiary_name'])) {
        $accountNumber = (string) ($transaction['beneficiary_account_number'] ?? '');
        $payload['beneficiary'] = [
            'name' => $transaction['beneficiary_name'],
            'bank_name' => $transaction['beneficiary_bank_name'] ?? null,
            // Never the full account number in an outbound webhook body.
            'account_last4' => $accountNumber !== '' ? substr($accountNumber, -4) : null,
            'ifsc' => $transaction['beneficiary_ifsc'] ?? null,
        ];
    }

    queue_customer_webhook($pdo, (int) $transaction['user_id'], (int) $transaction['id'], $url, $creds['webhook_signing_secret_encrypted'], $event, $payload);
}

/**
 * Chargeback events are always PayIn-side (a dispute can only ever target a
 * successful PayIn — see includes/chargeback_service.php), so they always
 * use payin_callback_url, same as a payin.success/payin.failed event would.
 * Payload is deliberately minimal and safe — no gateway/provider internals,
 * no other merchant's data, nothing beyond what the merchant needs to
 * reconcile their own books.
 *
 * @param array $chargeback a chargebacks table row.
 */
function dispatch_customer_chargeback_webhook(PDO $pdo, array $chargeback, string $event): void
{
    $credStmt = $pdo->prepare(
        'SELECT payin_callback_url, webhook_signing_secret_encrypted FROM customer_api_credentials WHERE user_id = ?'
    );
    $credStmt->execute([(int) $chargeback['user_id']]);
    $creds = $credStmt->fetch();
    if (!$creds || !$creds['payin_callback_url'] || !$creds['webhook_signing_secret_encrypted']) {
        return;
    }

    $txnStmt = $pdo->prepare('SELECT reference FROM transactions WHERE id = ?');
    $txnStmt->execute([(int) $chargeback['transaction_id']]);
    $originalReference = $txnStmt->fetchColumn() ?: null;

    $payload = [
        'event' => $event,
        'reference' => $originalReference,
        'chargeback_id' => 'CB-' . $chargeback['id'],
        'status' => strtoupper($chargeback['status']),
        'amount' => $chargeback['amount'],
        'fee' => $chargeback['fee'],
        'total_amount' => $chargeback['total_amount'],
        'currency' => $chargeback['currency'] ?? 'INR',
        'reason' => $chargeback['reason'] ?? null,
        'timestamp' => gmdate('c'),
    ];

    queue_customer_webhook($pdo, (int) $chargeback['user_id'], (int) $chargeback['transaction_id'], $creds['payin_callback_url'], $creds['webhook_signing_secret_encrypted'], $event, $payload);
}

/**
 * Shared queueing entry point for every outbound customer event this
 * platform sends — transaction settlement AND chargeback events alike.
 * Deliberately the ONE place a customer_webhook_deliveries row is ever
 * inserted, so there is exactly one retry/backoff/delivery-tracking system,
 * not one per event family.
 */
function queue_customer_webhook(PDO $pdo, int $userId, int $transactionId, ?string $url, ?string $webhookSecretEncrypted, string $event, array $payload): void
{
    if (!$url || !$webhookSecretEncrypted) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO customer_webhook_deliveries (transaction_id, user_id, event, url, payload, next_attempt_at)
         VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
    );
    $insert->execute([$transactionId, $userId, $event, $url, json_encode($payload)]);

    attempt_webhook_delivery($pdo, (int) $pdo->lastInsertId());
}

/**
 * Makes ONE delivery attempt for a queued row and updates it accordingly —
 * shared by the initial synchronous attempt above and every later retry
 * (process_webhook_retry_queue()). A no-op if the row is missing or
 * already resolved (delivered/failed), so it's safe to call redundantly.
 */
function attempt_webhook_delivery(PDO $pdo, int $deliveryId): void
{
    $stmt = $pdo->prepare('SELECT * FROM customer_webhook_deliveries WHERE id = ?');
    $stmt->execute([$deliveryId]);
    $delivery = $stmt->fetch();
    if (!$delivery || $delivery['status'] !== 'pending') {
        return;
    }

    $credStmt = $pdo->prepare('SELECT webhook_signing_secret_encrypted FROM customer_api_credentials WHERE user_id = ?');
    $credStmt->execute([$delivery['user_id']]);
    $secretEncrypted = $credStmt->fetchColumn();
    if (!$secretEncrypted) {
        // The customer's signing secret is gone entirely (shouldn't
        // normally happen — rotation replaces it, never clears it) —
        // nothing useful to retry towards.
        $pdo->prepare('UPDATE customer_webhook_deliveries SET status = "failed", last_error = ? WHERE id = ?')
            ->execute(['No webhook signing secret configured for this account.', $deliveryId]);
        return;
    }

    try {
        $secret = gateway_decrypt_secret($secretEncrypted);
    } catch (Throwable $e) {
        error_log('[customer_webhooks] failed to decrypt signing secret for user ' . $delivery['user_id'] . ': ' . $e->getMessage());
        return; // leave pending at its current next_attempt_at — a transient decrypt issue shouldn't burn an attempt
    }

    $body = $delivery['payload'];
    // Same scheme this app's own gateway webhook receivers document for a
    // generic HMAC integration (includes/gateway_webhooks.php) — hex
    // HMAC-SHA256 of the raw body, keyed by the customer's own signing
    // secret, so their receiver can verify it with one line of code.
    $signature = hash_hmac('sha256', $body, $secret);

    $ch = curl_init($delivery['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Verapay-Signature: ' . $signature,
            'X-Verapay-Event: ' . $delivery['event'],
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $attempts = (int) $delivery['attempts'] + 1;
    $transactionId = (int) $delivery['transaction_id'];

    if ($curlErrno === 0 && $httpStatus >= 200 && $httpStatus < 300) {
        $pdo->prepare('UPDATE customer_webhook_deliveries SET status = "delivered", attempts = ?, last_http_status = ?, last_error = NULL WHERE id = ?')
            ->execute([$attempts, $httpStatus, $deliveryId]);

        // Recorded for the transaction detail timeline
        // (public/api/transactions/detail.php) — "callback sent" only ever
        // appears once delivery has actually been confirmed 2xx.
        write_audit_log(null, 'customer_webhook_delivered', 'transaction', $transactionId, [
            'user_id' => (int) $delivery['user_id'],
            'event' => $delivery['event'],
            'attempts' => $attempts,
        ]);
        return;
    }

    $reason = $curlErrno !== 0 ? $curlError : "HTTP {$httpStatus}";
    error_log("[customer_webhooks] delivery attempt {$attempts} failed for transaction {$transactionId}: {$reason}");

    $exhausted = $attempts >= (int) $delivery['max_attempts'];
    if ($exhausted) {
        $pdo->prepare('UPDATE customer_webhook_deliveries SET status = "failed", attempts = ?, last_http_status = ?, last_error = ? WHERE id = ?')
            ->execute([$attempts, $curlErrno === 0 ? $httpStatus : null, $reason, $deliveryId]);
    } else {
        $backoffMinutes = WEBHOOK_RETRY_BACKOFF_MINUTES[$attempts - 1] ?? end(WEBHOOK_RETRY_BACKOFF_MINUTES);
        $pdo->prepare(
            'UPDATE customer_webhook_deliveries
             SET attempts = ?, last_http_status = ?, last_error = ?, next_attempt_at = UTC_TIMESTAMP() + INTERVAL ? MINUTE
             WHERE id = ?'
        )->execute([$attempts, $curlErrno === 0 ? $httpStatus : null, $reason, $backoffMinutes, $deliveryId]);
    }

    write_audit_log(null, 'customer_webhook_delivery_failed', 'transaction', $transactionId, [
        'user_id' => (int) $delivery['user_id'],
        'event' => $delivery['event'],
        'reason' => $reason,
        'attempts' => $attempts,
        'exhausted' => $exhausted,
    ]);
}

/**
 * Processes every due retry — bin/process-webhook-retries.php (cron) and
 * the admin "Retry now" button both call this directly.
 *
 * @return array{processed:int, delivered:int, failed:int, still_pending:int}
 */
function process_webhook_retry_queue(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->prepare(
        'SELECT id FROM customer_webhook_deliveries
         WHERE status = "pending" AND next_attempt_at <= UTC_TIMESTAMP()
         ORDER BY next_attempt_at ASC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $dueIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $results = ['processed' => 0, 'delivered' => 0, 'failed' => 0, 'still_pending' => 0];

    foreach ($dueIds as $id) {
        attempt_webhook_delivery($pdo, (int) $id);
        $results['processed']++;

        $statusStmt = $pdo->prepare('SELECT status FROM customer_webhook_deliveries WHERE id = ?');
        $statusStmt->execute([$id]);
        $status = $statusStmt->fetchColumn();
        if ($status === 'delivered') {
            $results['delivered']++;
        } elseif ($status === 'failed') {
            $results['failed']++;
        } else {
            $results['still_pending']++;
        }
    }

    return $results;
}
