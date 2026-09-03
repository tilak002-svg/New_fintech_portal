<?php
/**
 * Outbound delivery of transaction settlement events to a customer's own
 * payin_callback_url / payout_callback_url (Settings -> API access).
 *
 * Fire-and-forget: this app has no job queue, so delivery is a single
 * synchronous, short-timeout HTTP call made right after the DB transaction
 * that settled the underlying transaction has already committed - never
 * while holding any row lock (same rule deposit_service.php already
 * follows for outbound gateway calls). A delivery failure (network error,
 * non-2xx response) is logged and audited but never retried and never
 * surfaces to the caller - the customer's own dashboard/API remains the
 * source of truth regardless of whether this delivery succeeds.
 *
 * Silently does nothing if the customer never configured a callback URL
 * for this transaction's direction, or never has a signing secret yet
 * (issued lazily the first time they load Settings -> API access — see
 * public/api/settings/api-credentials.php) — both are normal, not errors.
 */

require_once __DIR__ . '/gateway_secrets.php';

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

    try {
        $secret = gateway_decrypt_secret($creds['webhook_signing_secret_encrypted']);
    } catch (Throwable $e) {
        error_log('[customer_webhooks] failed to decrypt signing secret for user ' . $transaction['user_id'] . ': ' . $e->getMessage());
        return;
    }

    $event = $transaction['type'] . '.' . $transaction['status'];
    $body = json_encode([
        'event' => $event,
        'reference' => $transaction['reference'],
        'type' => $transaction['type'],
        'status' => $transaction['status'],
        'amount' => $transaction['amount'],
        'fee' => $transaction['fee'],
        'net_amount' => $transaction['net_amount'],
        'currency' => $transaction['currency'] ?? 'INR',
        'occurred_at' => gmdate('c'),
    ]);
    // Same scheme this app's own gateway webhook receivers document for a
    // generic HMAC integration (includes/gateway_webhooks.php) — hex
    // HMAC-SHA256 of the raw body, keyed by the customer's own signing
    // secret, so their receiver can verify it with one line of code.
    $signature = hash_hmac('sha256', $body, $secret);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Verapay-Signature: ' . $signature,
            'X-Verapay-Event: ' . $event,
        ],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        $reason = $curlErrno !== 0 ? $curlError : "HTTP {$httpStatus}";
        error_log("[customer_webhooks] delivery to callback URL failed for transaction {$transaction['reference']}: {$reason}");
        write_audit_log(null, 'customer_webhook_delivery_failed', 'transaction', (int) ($transaction['id'] ?? 0), [
            'user_id' => $transaction['user_id'],
            'event' => $event,
            'reason' => $reason,
        ]);
    }
}
