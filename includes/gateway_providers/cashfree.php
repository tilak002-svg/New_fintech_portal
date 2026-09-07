<?php
/**
 * Cashfree-specific outbound integration: order creation (pay-in) and
 * inbound webhook signature/payload handling.
 *
 * API reference used (confirmed against Cashfree's published docs):
 * POST https://sandbox.cashfree.com/pg/orders (or api.cashfree.com/pg/orders
 * for production - Cashfree, unlike Razorpay, uses genuinely separate base
 * URLs per environment). Auth via x-client-id/x-client-secret/x-api-version
 * headers, not Basic Auth. customer_details.customer_phone is a REQUIRED
 * field Cashfree rejects orders without - see gateway_providers/dispatch.php
 * for where that's sourced from and how a missing phone is handled.
 *
 * Webhook signing: Base64Encode(HMAC-SHA256(x-webhook-timestamp . rawBody,
 * secret)) - note this concatenates the timestamp header directly onto the
 * raw body (no separator) before signing, and the header carrying the
 * webhook secret's counterpart is x-webhook-signature. Confirmed via
 * Cashfree's docs; distinct from Razorpay's simpler HMAC(rawBody) scheme.
 */

require_once __DIR__ . '/../gateway_secrets.php';

const CASHFREE_API_BASE_SANDBOX = 'https://sandbox.cashfree.com/pg';
const CASHFREE_API_BASE_PRODUCTION = 'https://api.cashfree.com/pg';
const CASHFREE_API_VERSION = '2023-08-01';

// Cashfree Payouts is a genuinely separate product from the Payments (PG)
// API above - different base path (/payout, not /pg) and its own
// dashboard-issued Client ID/Secret. This integration reuses
// payment_gateways.public_key/api_key_encrypted for whichever pair the
// admin entered — if a gateway is meant to do BOTH live pay-ins and live
// payouts through Cashfree, its stored credentials must be a pair that's
// actually enabled for both products on Cashfree's side; that's a Cashfree
// dashboard configuration question, not something this code can detect.
const CASHFREE_PAYOUT_API_BASE_SANDBOX = 'https://sandbox.cashfree.com/payout';
const CASHFREE_PAYOUT_API_BASE_PRODUCTION = 'https://api.cashfree.com/payout';
const CASHFREE_PAYOUT_API_VERSION = '2024-01-01';

/** Thrown when we could not confirm whether Cashfree actually created the order or not. */
class CashfreeAmbiguousException extends RuntimeException {}

/**
 * Creates a Cashfree order for a pay-in (deposit).
 *
 * @param array $gateway payment_gateways row - public_key holds the Client ID, api_key_encrypted the Client Secret, sandbox_mode picks the base URL.
 * @param array $customer must have 'id', 'phone' (required by Cashfree); 'email'/'name' optional.
 * @return array{order_id: string, cf_order_id: ?string, payment_session_id: string}
 * @throws CashfreeAmbiguousException on a network-level failure.
 * @throws RuntimeException on a definite, synchronous rejection, or missing required customer data.
 */
function cashfree_create_order(array $gateway, string $reference, string $amountRupees, array $customer, string $currency = 'INR'): array
{
    $clientId = trim((string) ($gateway['public_key'] ?? ''));
    if ($clientId === '' || empty($gateway['api_key_encrypted'])) {
        throw new RuntimeException('This gateway is missing its Cashfree Client ID or Client Secret.');
    }
    if (empty($customer['phone'])) {
        throw new RuntimeException('Your profile is missing a phone number, which Cashfree requires to start a payment. Add one in Settings and try again.');
    }

    $clientSecret = gateway_decrypt_secret($gateway['api_key_encrypted']);

    $payload = json_encode([
        'order_id' => $reference,
        'order_amount' => (float) $amountRupees,
        'order_currency' => $currency,
        'customer_details' => array_filter([
            'customer_id' => $customer['id'],
            'customer_phone' => $customer['phone'],
            'customer_email' => $customer['email'] ?? null,
            'customer_name' => $customer['name'] ?? null,
        ]),
    ]);

    $base = !empty($gateway['sandbox_mode']) ? CASHFREE_API_BASE_SANDBOX : CASHFREE_API_BASE_PRODUCTION;

    $ch = curl_init($base . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-client-id: ' . $clientId,
            'x-client-secret: ' . $clientSecret,
            'x-api-version: ' . CASHFREE_API_VERSION,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        throw new CashfreeAmbiguousException("Network error contacting Cashfree ({$curlError}) - order status unknown.");
    }

    $decoded = json_decode((string) $response, true);

    if ($httpStatus >= 200 && $httpStatus < 300 && isset($decoded['payment_session_id'])) {
        return [
            'order_id' => $decoded['order_id'] ?? $reference,
            'cf_order_id' => isset($decoded['cf_order_id']) ? (string) $decoded['cf_order_id'] : null,
            'payment_session_id' => $decoded['payment_session_id'],
        ];
    }

    $reason = $decoded['message'] ?? "unexpected HTTP {$httpStatus}";
    throw new RuntimeException("Cashfree rejected the order: {$reason}");
}

/**
 * Creates a Cashfree Payouts transfer for a withdrawal.
 *
 * API reference used (confirmed against Cashfree's published docs):
 * beneficiaries must be registered before a transfer can reference them
 * (POST /payout/beneficiary), then the transfer itself is
 * POST /payout/transfers with { transfer_id, transfer_amount,
 * beneficiary_details: { beneficiary_id } }. Auth is the same
 * x-client-id/x-client-secret/x-api-version header style as the PG API
 * (see file header for why the credential VALUES may still need to be a
 * different pair). Webhook signing is confirmed identical to the PG
 * webhook's scheme (timestamp+rawBody, HMAC-SHA256, base64) - see
 * cashfree_verify_webhook_signature(), reused as-is for payout webhooks.
 *
 * Beneficiary registration is idempotent by design (a fixed, per-user
 * beneficiary_id) but NOT self-correcting: if the customer's bank details
 * in settlement_banks change after their beneficiary was first registered,
 * Cashfree still has the old ones — this integration does not detect or
 * re-register on a mismatch. Known limitation; resolving it needs either
 * beneficiary deletion+recreation or Cashfree's beneficiary-update
 * endpoint, neither wired up here.
 *
 * @param array $gateway payment_gateways row — public_key is the Client ID, api_key_encrypted the Client Secret, sandbox_mode picks the base URL.
 * @param array $bank must have 'account_holder', 'account_number', 'ifsc_code'.
 * @param array $customer must have 'id', 'phone', 'address' (both required by Cashfree beneficiary registration).
 * @return array{transfer_id: string, cf_transfer_id: ?string, status: string}
 * @throws CashfreeAmbiguousException on a network-level failure during the transfer call specifically.
 * @throws RuntimeException on a definite, synchronous rejection, or missing required data.
 */
function cashfree_create_payout(array $gateway, string $reference, string $amountRupees, array $bank, array $customer): array
{
    $clientId = trim((string) ($gateway['public_key'] ?? ''));
    if ($clientId === '' || empty($gateway['api_key_encrypted'])) {
        throw new RuntimeException('This gateway is missing its Cashfree Client ID or Client Secret.');
    }
    if (empty($customer['phone'])) {
        throw new RuntimeException('Your profile is missing a phone number, which Cashfree requires to process a payout. Add one in Settings and try again.');
    }
    if (empty($customer['address'])) {
        throw new RuntimeException('Your profile is missing an office address, which Cashfree requires to process a payout. Add one in Settings and try again.');
    }

    $clientSecret = gateway_decrypt_secret($gateway['api_key_encrypted']);
    $base = !empty($gateway['sandbox_mode']) ? CASHFREE_PAYOUT_API_BASE_SANDBOX : CASHFREE_PAYOUT_API_BASE_PRODUCTION;
    $headers = [
        'Content-Type: application/json',
        'x-client-id: ' . $clientId,
        'x-client-secret: ' . $clientSecret,
        'x-api-version: ' . CASHFREE_PAYOUT_API_VERSION,
    ];
    $beneficiaryId = 'vpuser' . $customer['id'];

    // Registering a beneficiary that already exists is expected on every
    // payout after a user's first — Cashfree rejects the duplicate, which
    // is not a failure here, just confirmation the beneficiary is already
    // on file. Only a non-duplicate rejection is a real, definite failure.
    $beneficiaryResponse = cashfree_payout_request($base . '/beneficiary', 'POST', [
        'beneficiary_id' => $beneficiaryId,
        'beneficiary_name' => $bank['account_holder'],
        'beneficiary_instrument_details' => [
            'bank_account_number' => $bank['account_number'],
            'bank_ifsc' => $bank['ifsc_code'],
        ],
        'beneficiary_contact_details' => [
            'beneficiary_phone' => $customer['phone'],
            'beneficiary_address' => $customer['address'],
        ],
    ], $headers, 'register the payout beneficiary');

    if ($beneficiaryResponse['http_status'] >= 400) {
        $code = $beneficiaryResponse['decoded']['code'] ?? '';
        $isDuplicate = str_contains(strtolower((string) $code), 'duplicate')
            || str_contains(strtolower((string) ($beneficiaryResponse['decoded']['message'] ?? '')), 'already exist');
        if (!$isDuplicate) {
            $reason = $beneficiaryResponse['decoded']['message'] ?? "unexpected HTTP {$beneficiaryResponse['http_status']}";
            throw new RuntimeException("Cashfree rejected the payout beneficiary: {$reason}");
        }
    }

    // From here a network-level failure is ambiguous — the transfer may
    // have been created and just not acknowledged back to us.
    $ch = curl_init($base . '/transfers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'transfer_id' => $reference,
            'transfer_amount' => (float) $amountRupees,
            'beneficiary_details' => ['beneficiary_id' => $beneficiaryId],
        ]),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0) {
        throw new CashfreeAmbiguousException("Network error contacting Cashfree ({$curlError}) - transfer status unknown.");
    }

    $decoded = json_decode((string) $response, true);

    if ($httpStatus >= 200 && $httpStatus < 300 && isset($decoded['transfer_id'])) {
        return [
            'transfer_id' => (string) $decoded['transfer_id'],
            'cf_transfer_id' => isset($decoded['cf_transfer_id']) ? (string) $decoded['cf_transfer_id'] : null,
            'status' => $decoded['status'] ?? 'PENDING',
        ];
    }

    $reason = $decoded['message'] ?? "unexpected HTTP {$httpStatus}";
    throw new RuntimeException("Cashfree rejected the payout: {$reason}");
}

/** Shared POST helper for the beneficiary-registration prerequisite call above — returns the raw HTTP status alongside the decoded body so the caller can distinguish "already exists" from a real rejection. */
function cashfree_payout_request(string $url, string $method, array $body, array $headers, string $actionDescription): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => $headers,
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

    return ['http_status' => $httpStatus, 'decoded' => json_decode((string) $response, true)];
}

/**
 * Reconciliation only (includes/reconciliation.php) — GET /pg/orders/{id}
 * (confirmed against Cashfree's docs) for a pay-in order's current
 * order_status. Never throws — see razorpay_fetch_payin_status()'s
 * docblock for why "don't know yet" must never become a guessed outcome.
 *
 * @return string one of 'success', 'failed', 'pending', 'unknown'
 */
function cashfree_fetch_payin_status(array $gateway, string $orderId): string
{
    $clientId = trim((string) ($gateway['public_key'] ?? ''));
    if ($clientId === '' || empty($gateway['api_key_encrypted'])) {
        return 'unknown';
    }

    try {
        $clientSecret = gateway_decrypt_secret($gateway['api_key_encrypted']);
    } catch (Throwable $e) {
        return 'unknown';
    }

    $base = !empty($gateway['sandbox_mode']) ? CASHFREE_API_BASE_SANDBOX : CASHFREE_API_BASE_PRODUCTION;

    $ch = curl_init($base . '/orders/' . rawurlencode($orderId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-client-id: ' . $clientId,
            'x-client-secret: ' . $clientSecret,
            'x-api-version: ' . CASHFREE_API_VERSION,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        return 'unknown';
    }

    $decoded = json_decode((string) $response, true);
    return match ($decoded['order_status'] ?? '') {
        'PAID' => 'success',
        'EXPIRED', 'TERMINATED' => 'failed',
        default => 'pending',
    };
}

/**
 * Reconciliation only — GET /payout/transfers?transfer_id={id} for a
 * payout's current status. Same never-throws contract.
 */
function cashfree_fetch_payout_status(array $gateway, string $transferId): string
{
    $clientId = trim((string) ($gateway['public_key'] ?? ''));
    if ($clientId === '' || empty($gateway['api_key_encrypted'])) {
        return 'unknown';
    }

    try {
        $clientSecret = gateway_decrypt_secret($gateway['api_key_encrypted']);
    } catch (Throwable $e) {
        return 'unknown';
    }

    $base = !empty($gateway['sandbox_mode']) ? CASHFREE_PAYOUT_API_BASE_SANDBOX : CASHFREE_PAYOUT_API_BASE_PRODUCTION;

    $ch = curl_init($base . '/transfers?transfer_id=' . rawurlencode($transferId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-client-id: ' . $clientId,
            'x-client-secret: ' . $clientSecret,
            'x-api-version: ' . CASHFREE_PAYOUT_API_VERSION,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        return 'unknown';
    }

    $decoded = json_decode((string) $response, true);
    $status = $decoded['status'] ?? ($decoded[0]['status'] ?? '');
    return match ($status) {
        'SUCCESS' => 'success',
        'FAILED', 'REVERSED' => 'failed',
        default => 'pending',
    };
}

/**
 * Maps a Cashfree dispute/chargeback webhook into the generic chargeback
 * event shape process_chargeback_event() (includes/chargeback_service.php)
 * understands. Returns null for anything that isn't a dispute event.
 *
 * IMPORTANT — best-effort mapping, same caveat this file's own header
 * already makes for the payment/transfer webhook shapes above: modeled on
 * Cashfree's published PG API conventions (a `type` field + nested
 * `data.dispute` object, mirroring PAYMENT_*_WEBHOOK) but the exact event
 * type strings and dispute object field names have NOT been confirmed
 * against a live dispute webhook delivery from Cashfree's dashboard.
 * Verify $disputeStatusMap and the field names read from
 * $payload['data']['dispute'] against a real captured event (or Cashfree's
 * current Disputes API docs) before relying on this for real disputes —
 * signature verification and idempotency below are unaffected either way.
 */
function cashfree_parse_chargeback_webhook_payload(array $payload): ?array
{
    $type = (string) ($payload['type'] ?? '');
    $dispute = $payload['data']['dispute'] ?? null;

    if (!is_array($dispute) || !str_starts_with($type, 'DISPUTE_')) {
        return null;
    }

    $disputeId = (string) ($dispute['dispute_id'] ?? $dispute['cf_dispute_id'] ?? '');
    $orderId = (string) ($dispute['order_id'] ?? '');
    if ($disputeId === '' || $orderId === '') {
        return null;
    }

    $providerStatus = (string) ($dispute['dispute_status'] ?? $type);
    $disputeStatusMap = [
        'DISPUTE_CREATED' => 'open',
        'OPEN' => 'open',
        'ACTION_REQUIRED' => 'pending',
        'UNDER_REVIEW' => 'pending',
        'DISPUTE_WON' => 'won',
        'WON' => 'won',
        'DISPUTE_LOST' => 'lost',
        'LOST' => 'lost',
        'REVERSED' => 'reversed',
    ];
    $normalized = $disputeStatusMap[strtoupper($providerStatus)] ?? $disputeStatusMap[strtoupper($type)] ?? null;
    if ($normalized === null) {
        return null;
    }

    $occurredAt = cashfree_parse_timestamp($dispute['updated_at'] ?? $payload['event_time'] ?? null);

    return [
        // Cashfree doesn't send a distinct delivery/event id for disputes
        // any more than it does for payments (see
        // cashfree_parse_webhook_payload() above) — a dispute reaching a
        // given status is itself a stable, naturally-deduplicating
        // identifier for redelivery.
        'gateway_event_id' => "{$disputeId}:{$providerStatus}",
        'gateway_chargeback_id' => $disputeId,
        'reference' => $orderId,
        'event_type' => $type,
        'normalized_status' => $normalized,
        'provider_status' => $providerStatus,
        'amount' => isset($dispute['dispute_amount']) ? (string) $dispute['dispute_amount'] : null,
        'fee' => isset($dispute['dispute_fee']) ? (string) $dispute['dispute_fee'] : null,
        'currency' => $dispute['currency'] ?? 'INR',
        'reason_code' => $dispute['reason_code'] ?? null,
        'reason' => $dispute['reason'] ?? null,
        'occurred_at' => $occurredAt,
        'due_at' => cashfree_parse_timestamp($dispute['respond_by'] ?? null),
        'raw' => $payload,
    ];
}

/**
 * Reconciliation only (includes/reconciliation.php::reconcile_chargebacks())
 * — fetches a single dispute's CURRENT status directly from Cashfree, for a
 * chargeback we already have on file. Same never-throws / never-guess
 * contract as cashfree_fetch_payin_status(): any failure returns null
 * ("still don't know"), never a guessed outcome.
 *
 * IMPORTANT — best-effort endpoint, same caveat as
 * cashfree_parse_chargeback_webhook_payload(): modeled on Cashfree's
 * general GET-by-id resource convention (GET /pg/disputes/{dispute_id}),
 * not confirmed against live docs. Verify before relying on this in
 * production.
 *
 * @return array{provider_status: string, normalized_status: string}|null
 */
function cashfree_fetch_dispute_status(array $gateway, string $disputeId): ?array
{
    $clientId = trim((string) ($gateway['public_key'] ?? ''));
    if ($clientId === '' || empty($gateway['api_key_encrypted'])) {
        return null;
    }

    try {
        $clientSecret = gateway_decrypt_secret($gateway['api_key_encrypted']);
    } catch (Throwable $e) {
        return null;
    }

    $base = !empty($gateway['sandbox_mode']) ? CASHFREE_API_BASE_SANDBOX : CASHFREE_API_BASE_PRODUCTION;

    $ch = curl_init($base . '/disputes/' . rawurlencode($disputeId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-client-id: ' . $clientId,
            'x-client-secret: ' . $clientSecret,
            'x-api-version: ' . CASHFREE_API_VERSION,
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrno !== 0 || $httpStatus < 200 || $httpStatus >= 300) {
        return null;
    }

    $decoded = json_decode((string) $response, true);
    $providerStatus = (string) ($decoded['dispute_status'] ?? '');
    if ($providerStatus === '') {
        return null;
    }

    $disputeStatusMap = [
        'DISPUTE_CREATED' => 'open', 'OPEN' => 'open',
        'ACTION_REQUIRED' => 'pending', 'UNDER_REVIEW' => 'pending',
        'DISPUTE_WON' => 'won', 'WON' => 'won',
        'DISPUTE_LOST' => 'lost', 'LOST' => 'lost',
        'REVERSED' => 'reversed',
    ];
    $normalized = $disputeStatusMap[strtoupper($providerStatus)] ?? null;
    if ($normalized === null) {
        return null;
    }

    return ['provider_status' => $providerStatus, 'normalized_status' => $normalized];
}

/** Parses a Cashfree ISO-ish timestamp string into 'Y-m-d H:i:s' UTC, or null if absent/unparseable. */
function cashfree_parse_timestamp(?string $raw): ?string
{
    if (!$raw) {
        return null;
    }
    $ts = strtotime($raw);
    return $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
}

function cashfree_verify_webhook_signature(string $rawBody, string $timestampHeader, string $signatureHeader, string $secret): bool
{
    if ($timestampHeader === '' || $signatureHeader === '' || $secret === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $timestampHeader . $rawBody, $secret, true));
    return hash_equals($expected, trim($signatureHeader));
}

/**
 * Maps Cashfree's webhook shape into the generic
 * {event_id, reference, gateway_txn_id, status} shape
 * process_gateway_webhook() understands. Returns null for event types this
 * integration doesn't act on.
 *
 * Two independent event families land here, both correlated by `reference`
 * — Cashfree's own docs confirm both order_id (pay-ins) and transfer_id
 * (payouts) are the merchant-supplied value echoed back as-is:
 *   - PAYMENT_*_WEBHOOK (pay-ins): the PG API's `type` shape, data.order/data.payment.
 *   - TRANSFER_*_WEBHOOK (payouts): the Payouts V2 API's shape, data.transfer_id/data.cf_transfer_id/data.status.
 */
function cashfree_parse_webhook_payload(array $payload): ?array
{
    $type = $payload['type'] ?? '';

    $order = $payload['data']['order'] ?? null;
    $payment = $payload['data']['payment'] ?? null;
    $paymentStatusMap = [
        'PAYMENT_SUCCESS_WEBHOOK' => 'success',
        'PAYMENT_FAILED_WEBHOOK' => 'failed',
        'PAYMENT_USER_DROPPED_WEBHOOK' => 'failed',
    ];
    if ($order && $payment && isset($paymentStatusMap[$type]) && !empty($order['order_id'])) {
        $paymentId = (string) ($payment['cf_payment_id'] ?? '');
        if ($paymentId === '') {
            return null;
        }
        return [
            // Cashfree doesn't send a distinct event/delivery id - a given
            // payment reaching a given terminal status is itself a stable,
            // naturally-deduplicating identifier for redelivery.
            'event_id' => "{$paymentId}:{$type}",
            'reference' => (string) $order['order_id'],
            'gateway_txn_id' => $paymentId,
            'status' => $paymentStatusMap[$type],
        ];
    }

    $transferData = $payload['data'] ?? null;
    $transferStatusMap = [
        'TRANSFER_SUCCESS' => 'success',
        'TRANSFER_FAILED' => 'failed',
        'TRANSFER_REVERSED' => 'failed',
    ];
    if ($transferData && isset($transferStatusMap[$type]) && !empty($transferData['transfer_id'])) {
        $cfTransferId = (string) ($transferData['cf_transfer_id'] ?? '');
        return [
            'event_id' => $cfTransferId !== '' ? "{$cfTransferId}:{$type}" : "{$transferData['transfer_id']}:{$type}",
            'reference' => (string) $transferData['transfer_id'],
            'gateway_txn_id' => $cfTransferId !== '' ? $cfTransferId : null,
            'status' => $transferStatusMap[$type],
        ];
    }

    return null;
}
