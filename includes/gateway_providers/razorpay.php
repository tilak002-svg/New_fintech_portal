<?php
/**
 * Razorpay-specific outbound integration: order creation (pay-in) and
 * inbound webhook signature/payload handling.
 *
 * API reference used: POST https://api.razorpay.com/v1/orders (Basic Auth
 * key_id:key_secret; amount in integer paise; returns {"id": "order_..."})
 * and webhook signing (HMAC-SHA256 hex of the RAW body, keyed by the
 * webhook secret, in X-Razorpay-Signature). Confirmed against Razorpay's
 * published docs. What's NOT independently confirmed: whether Razorpay
 * copies an order's `notes` onto the resulting payment entity in webhook
 * payloads — so correlation here deliberately does NOT depend on that.
 * Instead the order_id we get back synchronously at creation time is
 * stored as transactions.gateway_txn_id immediately, and the webhook is
 * matched back to it via payload.payment.entity.order_id, which Razorpay's
 * docs do guarantee is present.
 *
 * No Razorpay SDK/Composer dependency - plain cURL, consistent with this
 * project's architecture.
 */

require_once __DIR__ . '/../gateway_secrets.php';

const RAZORPAY_API_BASE = 'https://api.razorpay.com/v1';

/** Thrown when we could not confirm whether Razorpay actually created the order or not (e.g. network timeout). Caller must NOT treat this as a definite failure. */
class RazorpayAmbiguousException extends RuntimeException {}

/**
 * Creates a Razorpay order for a pay-in (deposit).
 *
 * @param array $gateway payment_gateways row - must have public_key and api_key_encrypted set.
 * @return array{order_id: string, key_id: string, amount_paise: int}
 * @throws RazorpayAmbiguousException on a network-level failure - the order may or may not have been created.
 * @throws RuntimeException on a definite, synchronous rejection from Razorpay (safe to treat as "did not happen").
 */
function razorpay_create_order(array $gateway, string $reference, string $amountRupees, string $currency = 'INR'): array
{
    $keyId = trim((string) ($gateway['public_key'] ?? ''));
    if ($keyId === '' || empty($gateway['api_key_encrypted'])) {
        throw new RuntimeException('This gateway is missing its Razorpay key_id or key_secret.');
    }

    $keySecret = gateway_decrypt_secret($gateway['api_key_encrypted']);
    $amountPaise = (int) round(((float) $amountRupees) * 100);

    $payload = json_encode([
        'amount' => $amountPaise,
        'currency' => $currency,
        'receipt' => $reference,
    ]);

    $ch = curl_init(RAZORPAY_API_BASE . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        throw new RazorpayAmbiguousException("Network error contacting Razorpay ({$curlError}) - order status unknown.");
    }

    $decoded = json_decode((string) $response, true);

    if ($httpStatus >= 200 && $httpStatus < 300 && isset($decoded['id'])) {
        return ['order_id' => $decoded['id'], 'key_id' => $keyId, 'amount_paise' => $amountPaise];
    }

    // A clean HTTP response (even an error one) means Razorpay definitely
    // responded - no order was created. Safe to treat as a definite failure.
    $reason = $decoded['error']['description'] ?? "unexpected HTTP {$httpStatus}";
    throw new RuntimeException("Razorpay rejected the order: {$reason}");
}

/**
 * Creates a RazorpayX payout for a withdrawal, transferring to the
 * customer's bank account.
 *
 * API reference used (confirmed against Razorpay's published docs):
 * a payout needs a Fund Account, which needs a Contact - three real,
 * sequential outbound calls: POST /v1/contacts, POST /v1/fund_accounts,
 * POST /v1/payouts. All three use the same Basic Auth as order creation -
 * RazorpayX payout keys are generated from the same dashboard.
 * `account_number` in the final call is RazorpayX's source current account
 * (payment_gateways.payout_account_number here), NOT the beneficiary's
 * account - that's what bank_account.account_number is, three lines below.
 * `X-Payout-Idempotency` is required by Razorpay's own docs for
 * successful payout creation; the withdrawal reference (already unique per
 * transaction) is used verbatim so a retried request can never double-pay.
 *
 * Confirmed via docs: reference_id is described as "your transaction ID" -
 * a merchant-supplied passthrough field, which is what lets the webhook
 * correlate a payout event back to our transaction by `reference` (see
 * razorpay_parse_webhook_payload() below) the same way Cashfree's payin
 * flow already does, rather than needing gateway_txn_id like Razorpay's
 * own payin flow does (see file header - Razorpay orders don't confirm
 * notes/reference echoing, but payouts' reference_id is documented as
 * exactly this passthrough).
 *
 * A contact/fund-account created here is NOT reused across payouts (no
 * lookup-or-create) - simplest correct thing, at the cost of accumulating
 * one contact+fund-account per payout at Razorpay. Money only ever moves
 * in the final /payouts call, so a failure in either of the first two
 * steps is always a safe, definite failure - nothing to unwind.
 *
 * @param array $gateway payment_gateways row - needs public_key, api_key_encrypted, payout_account_number.
 * @param array $bank must have 'account_holder', 'account_number', 'ifsc_code'.
 * @return array{payout_id: string, status: string}
 * @throws RazorpayAmbiguousException on a network-level failure during the FINAL /payouts call specifically - money may or may not have moved.
 * @throws RuntimeException on a definite, synchronous rejection, or missing configuration.
 */
function razorpay_create_payout(array $gateway, string $reference, string $amountRupees, array $bank): array
{
    $keyId = trim((string) ($gateway['public_key'] ?? ''));
    $sourceAccount = trim((string) ($gateway['payout_account_number'] ?? ''));
    if ($keyId === '' || empty($gateway['api_key_encrypted'])) {
        throw new RuntimeException('This gateway is missing its Razorpay key_id or key_secret.');
    }
    if ($sourceAccount === '') {
        throw new RuntimeException('This gateway is missing its RazorpayX payout source account number.');
    }

    $keySecret = gateway_decrypt_secret($gateway['api_key_encrypted']);
    $auth = $keyId . ':' . $keySecret;
    $amountPaise = (int) round(((float) $amountRupees) * 100);

    // Step 1 — Contact. A definite failure here means nothing was created
    // at Razorpay and no money moved — safe to treat as an ordinary
    // RuntimeException the caller unwinds the reservation for.
    $contact = razorpay_x_post('/contacts', [
        'name' => $bank['account_holder'],
        'type' => 'customer',
        'reference_id' => $reference,
    ], $auth, 'create the payout contact');
    $contactId = $contact['id'] ?? null;
    if (!is_string($contactId) || $contactId === '') {
        throw new RuntimeException('Razorpay did not return a contact id.');
    }

    // Step 2 — Fund account. Same reasoning as step 1: still no money moved.
    $fundAccount = razorpay_x_post('/fund_accounts', [
        'contact_id' => $contactId,
        'account_type' => 'bank_account',
        'bank_account' => [
            'name' => $bank['account_holder'],
            'ifsc' => $bank['ifsc_code'],
            'account_number' => $bank['account_number'],
        ],
    ], $auth, 'register the payout bank account');
    $fundAccountId = $fundAccount['id'] ?? null;
    if (!is_string($fundAccountId) || $fundAccountId === '') {
        throw new RuntimeException('Razorpay did not return a fund account id.');
    }

    // Step 3 — the actual payout. From here a network-level failure is
    // ambiguous (the payout may have been created and just not
    // acknowledged back to us) — never retried on a different gateway.
    $ch = curl_init(RAZORPAY_API_BASE . '/payouts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'account_number' => $sourceAccount,
            'fund_account_id' => $fundAccountId,
            'amount' => $amountPaise,
            'currency' => 'INR',
            'mode' => 'IMPS',
            'purpose' => 'payout',
            'queue_if_low_balance' => true,
            'reference_id' => $reference,
            'narration' => substr('Verapay ' . $reference, 0, 30),
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Payout-Idempotency: ' . $reference,
        ],
        CURLOPT_USERPWD => $auth,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        throw new RazorpayAmbiguousException("Network error contacting Razorpay ({$curlError}) - payout status unknown.");
    }

    $decoded = json_decode((string) $response, true);

    if ($httpStatus >= 200 && $httpStatus < 300 && isset($decoded['id'])) {
        return ['payout_id' => $decoded['id'], 'status' => $decoded['status'] ?? 'processing'];
    }

    $reason = $decoded['error']['description'] ?? "unexpected HTTP {$httpStatus}";
    throw new RuntimeException("Razorpay rejected the payout: {$reason}");
}

/**
 * Shared POST helper for the contact/fund_account prerequisite calls above
 * — both are definite-failure-only (see razorpay_create_payout()'s
 * docblock), so unlike the main order/payout calls this deliberately does
 * NOT distinguish a network error from a rejection; either way, nothing
 * financial happened and it's safe to throw an ordinary RuntimeException.
 */
function razorpay_x_post(string $path, array $body, string $auth, string $actionDescription): array
{
    $ch = curl_init(RAZORPAY_API_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_USERPWD => $auth,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        throw new RuntimeException("Network error trying to {$actionDescription} ({$curlError}).");
    }

    $decoded = json_decode((string) $response, true);
    if ($httpStatus >= 200 && $httpStatus < 300 && is_array($decoded)) {
        return $decoded;
    }

    $reason = $decoded['error']['description'] ?? "unexpected HTTP {$httpStatus}";
    throw new RuntimeException("Razorpay refused to {$actionDescription}: {$reason}");
}

function razorpay_verify_webhook_signature(string $rawBody, string $signatureHeader, string $secret): bool
{
    if ($signatureHeader === '' || $secret === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, trim($signatureHeader));
}

/**
 * Maps Razorpay's actual webhook shape into the generic
 * {event_id, reference, gateway_txn_id, status} shape
 * process_gateway_webhook() understands. Returns null for event types this
 * integration doesn't act on (Razorpay sends many).
 *
 * Two independent event families land here, correlated differently — see
 * each section's comment for why:
 *   - payment.* (pay-ins): by order_id (via gateway_txn_id) — order
 *     creation's `notes`/reference echoing back isn't confirmed, see file
 *     header, so correlation deliberately does not depend on it.
 *   - payout.* (payouts): by reference_id (via `reference`) — confirmed as
 *     a merchant-supplied passthrough field, see razorpay_create_payout().
 */
function razorpay_parse_webhook_payload(array $payload): ?array
{
    $event = $payload['event'] ?? '';

    $payment = $payload['payload']['payment']['entity'] ?? null;
    $paymentStatusMap = [
        'payment.captured' => 'success',
        'payment.failed' => 'failed',
    ];
    if ($payment && isset($paymentStatusMap[$event]) && !empty($payment['order_id'])) {
        return [
            'event_id' => $payload['id'] ?? ($payment['id'] . ':' . $event),
            'reference' => null,
            'gateway_txn_id' => $payment['order_id'],
            'status' => $paymentStatusMap[$event],
        ];
    }

    $payout = $payload['payload']['payout']['entity'] ?? null;
    $payoutStatusMap = [
        'payout.processed' => 'success',
        'payout.failed' => 'failed',
        'payout.reversed' => 'failed',
    ];
    if ($payout && isset($payoutStatusMap[$event]) && !empty($payout['reference_id'])) {
        return [
            'event_id' => $payload['id'] ?? ($payout['id'] . ':' . $event),
            'reference' => (string) $payout['reference_id'],
            'gateway_txn_id' => $payout['id'] ?? null,
            'status' => $payoutStatusMap[$event],
        ];
    }

    return null;
}
