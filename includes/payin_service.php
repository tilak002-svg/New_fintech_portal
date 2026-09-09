<?php
/**
 * Merchant-facing PayIn: the caller (a merchant, authenticated via
 * api_guard()) collects a payment from their OWN end-customer. Adapted
 * from deposit_service.php — reuses the same gateway selection, capacity
 * reservation, and exception-triage machinery verbatim; the divergence is
 * who the "payer" is (the end-customer, not the caller) and what the
 * caller gets back (a Verapay-hosted payment_url via payment_sessions,
 * never the raw provider checkout payload — see database/migration9.sql).
 *
 * Same return convention as deposit_service.php — never calls
 * json_response() directly:
 *   ['ok' => bool, 'status_code' => int, 'message' => string, 'data' => array|null]
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/gateway_selector.php';
require_once __DIR__ . '/gateway_webhooks.php';
require_once __DIR__ . '/gateway_providers/dispatch.php';
require_once __DIR__ . '/customer_webhooks.php';

function create_payin(PDO $pdo, array $merchant, array $input, bool $sandboxOnly = false): array
{
    $merchantOrderId = trim((string) ($input['merchant_order_id'] ?? ''));
    if ($merchantOrderId === '' || mb_strlen($merchantOrderId) > 120) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'merchant_order_id is required (max 120 characters).', 'data' => null];
    }

    // Idempotency is derived from the merchant's own order id, not a
    // caller-supplied key — this makes retrying a create call with the
    // same merchant_order_id safe by construction, and
    // uq_transactions_merchant_order is a belt-and-suspenders backstop
    // against the same race a bare unique index alone wouldn't fully close.
    $idempotencyKey = 'PI:' . $merchant['id'] . ':' . $merchantOrderId;
    $existingStmt = $pdo->prepare(
        'SELECT reference, status, amount, fee, net_amount, merchant_order_id FROM transactions WHERE idempotency_key = ?'
    );
    $existingStmt->execute([$idempotencyKey]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        return [
            'ok' => true,
            'status_code' => 200,
            'message' => 'Replayed: a payin with this merchant_order_id already exists.',
            'data' => [
                'reference' => $existing['reference'],
                'status' => $existing['status'],
                'amount' => $existing['amount'],
                'fee' => $existing['fee'],
                'net_amount' => $existing['net_amount'],
                'merchant_order_id' => $existing['merchant_order_id'],
                'payment_url' => null,
                'expires_at' => null,
                'replayed' => true,
            ],
        ];
    }

    $currency = strtoupper(trim((string) ($input['currency'] ?? 'INR')));
    if ($currency !== 'INR') {
        return ['ok' => false, 'status_code' => 422, 'message' => 'Only INR is supported at this time.', 'data' => null];
    }

    $amount = sanitize_amount($input['amount'] ?? null);
    if ($amount === null || money_cmp($amount, '10.00') < 0) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'Enter an amount of at least ₹10.00.', 'data' => null];
    }
    if (money_cmp($amount, '50000.00') > 0) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'PayIns are limited to ₹50,000.00 per transaction.', 'data' => null];
    }

    $endCustomerName = trim((string) ($input['end_customer_name'] ?? ''));
    if ($endCustomerName === '' || mb_strlen($endCustomerName) > 120) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'end_customer_name is required (max 120 characters).', 'data' => null];
    }
    $endCustomerEmail = trim((string) ($input['end_customer_email'] ?? '')) ?: null;
    // Required unconditionally rather than only when the gateway that
    // happens to be selected needs it (Cashfree does, Razorpay doesn't) —
    // gateway selection isn't caller-controlled, so the API's required
    // fields must not depend on it.
    $endCustomerPhone = trim((string) ($input['end_customer_phone'] ?? ''));
    if ($endCustomerPhone === '' || mb_strlen($endCustomerPhone) > 20) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'end_customer_phone is required.', 'data' => null];
    }
    $returnUrl = trim((string) ($input['return_url'] ?? '')) ?: null;
    if ($returnUrl !== null && mb_strlen($returnUrl) > 255) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'return_url must be 255 characters or fewer.', 'data' => null];
    }

    $fee = calculate_fee('deposit', 'API', $amount);
    $net = money_sub($amount, $fee);
    $reference = generate_reference('deposit');

    $pdo->beginTransaction();
    try {
        // Capacity is reserved against the gross amount — the figure that
        // actually flows through the pay-in gateway — same rule as
        // deposit_service.php::create_deposit().
        $selection = select_and_reserve_gateway($pdo, $amount, $sandboxOnly, 'payin');
        if ($selection['gateway'] === null) {
            $pdo->rollBack();
            write_audit_log($merchant['id'], 'payin_gateway_unavailable', 'transaction', null, ['amount' => $amount, 'merchant_order_id' => $merchantOrderId, 'reason' => $selection['reason']]);
            // Not alerted for the sandboxOnly path — that's just the API
            // docs' "Try it" tester finding no sandbox gateway configured
            // yet, not a real production incident.
            if (!$sandboxOnly) {
                $isNoCapacity = $selection['reason'] === 'no_eligible_gateway';
                notify_admins(
                    $pdo,
                    'gateway',
                    $isNoCapacity ? 'PayIn blocked: every gateway is paused or over its limit' : 'PayIn blocked: no active payment gateway',
                    $isNoCapacity
                        ? "A payin for {$amount} INR could not be routed — every active gateway is either auto-paused or would exceed a configured daily/hourly/monthly/per-transaction limit. Source: limit reached."
                        : 'A payin could not be routed — no payment gateway is active. Source: our application (configuration).'
                );
            }
            $message = $sandboxOnly
                ? 'No sandbox-mode gateway is currently configured. Ask your platform admin to enable one for testing.'
                : 'PayIns are temporarily unavailable. Please try again shortly.';
            return ['ok' => false, 'status_code' => 503, 'message' => $message, 'data' => null];
        }
        $gateway = $selection['gateway'];
        $gatewayId = (int) $gateway['id'];
        $liveGatewayConfigured = gateway_supports_live_order_creation($gateway);
        // Distinct from $liveGatewayConfigured: a gateway an admin explicitly
        // flagged as mock/test (Admin -> Payment gateways) vs. a real
        // razorpay/cashfree row that simply isn't fully configured yet. Both
        // are "not live-configured", but only the former should ever be
        // silently simulated — see the three-way branch below.
        $isMockGateway = !empty($gateway['is_mock']);
        // Always created pending and held in pending_balance, then resolved
        // through the exact same apply_transaction_outcome() the webhook/
        // reconciliation paths use — including the instant-success sandbox
        // path below (see the no-live-gateway branch further down) — so
        // every payin, live or sandbox, leaves an identical audit/timeline
        // trail and only one function ever moves money out of pending_balance.
        $status = 'pending';

        $insert = $pdo->prepare(
            'INSERT INTO transactions (user_id, type, method, amount, fee, net_amount, currency, status, reference, destination, gateway_id, idempotency_key, merchant_order_id, end_customer_name, end_customer_email, end_customer_phone)
             VALUES (?, "deposit", "API", ?, ?, ?, "INR", ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$merchant['id'], $amount, $fee, $net, $status, $reference, $endCustomerName, $gatewayId, $idempotencyKey, $merchantOrderId, $endCustomerName, $endCustomerEmail, $endCustomerPhone]);
        $txnId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT IGNORE INTO wallets (user_id, available_balance, pending_balance, currency) VALUES (?, 0.00, 0.00, "INR")')->execute([$merchant['id']]);
        $pdo->prepare('UPDATE wallets SET pending_balance = pending_balance + ? WHERE user_id = ?')->execute([$net, $merchant['id']]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, "deposit", ?, ?)')
            ->execute([$merchant['id'], 'PayIn pending', "A payin of " . money_format($amount, 'INR') . " ({$reference}) is awaiting payment confirmation."]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[create_payin] ' . $e->getMessage());
        return ['ok' => false, 'status_code' => 500, 'message' => 'Unable to process this payin right now. Please try again.', 'data' => null];
    }

    write_audit_log($merchant['id'], 'payin_created', 'transaction', $txnId, ['amount' => $amount, 'merchant_order_id' => $merchantOrderId, 'status' => $status, 'gateway_id' => $gatewayId]);

    // Outbound call happens only after commit — never while holding the
    // wallet/usage row locks above (same rule as create_deposit()).
    $paymentUrl = null;
    $expiresAt = null;
    $message = $status === 'success' ? 'PayIn completed.' : 'PayIn created — awaiting payment.';

    if ($liveGatewayConfigured) {
        // The checkout identity is the END-CUSTOMER, not the merchant —
        // this is the one call-site divergence from create_deposit().
        // Razorpay's create_gateway_order() doesn't use this array at all
        // (no customer field in its request body); only Cashfree does.
        $checkoutIdentity = [
            'id' => $txnId,
            'email' => $endCustomerEmail ?: $merchant['email'],
            'name' => $endCustomerName,
        ];

        try {
            $orderResult = create_gateway_order($gateway, $reference, $amount, $checkoutIdentity, $endCustomerPhone);
            $pdo->prepare('UPDATE transactions SET gateway_txn_id = ? WHERE id = ?')->execute([$orderResult['gateway_txn_id'], $txnId]);
            write_audit_log($merchant['id'], 'payin_gateway_request_sent', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider']]);

            // Wrap the raw provider checkout payload behind a Verapay-hosted
            // session — the merchant's API response must never contain
            // provider identity/keys (see pages/pay-checkout.php).
            $sessionToken = bin2hex(random_bytes(32));
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);
            $pdo->prepare(
                'INSERT INTO payment_sessions (session_token, transaction_id, gateway_id, checkout_payload, return_url, status, expires_at)
                 VALUES (?, ?, ?, ?, ?, "created", ?)'
            )->execute([$sessionToken, $txnId, $gatewayId, json_encode($orderResult['checkout']), $returnUrl, $expiresAt]);

            $paymentUrl = rtrim(APP_URL, '/') . '/pay?session=' . $sessionToken;
            $message = 'Redirect your customer to payment_url to complete this payin.';
        } catch (GatewayOrderAmbiguousException $e) {
            // We do not know if the provider actually created the order —
            // never auto-retry on a different gateway. Left pending with
            // no session; only a webhook or manual reconciliation resolves it.
            error_log('[create_payin] gateway order ambiguous: ' . $e->getMessage());
            write_audit_log($merchant['id'], 'payin_gateway_order_ambiguous', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider'], 'reason' => $e->getMessage()]);
            notify_admins(
                $pdo,
                'gateway',
                "{$gateway['display_name']} — could not confirm order creation",
                'A payin request timed out or failed at the network level before this gateway confirmed it. Source: connectivity (not a definite provider or application error).'
            );
            $message = 'PayIn created, but we could not confirm the payment gateway accepted it yet. This will update automatically once confirmed.';
        } catch (Throwable $e) {
            // A definite, synchronous rejection — safe to unwind. Three
            // distinct causes share this one unwind path (rollback the
            // reservation, mark failed, notify the customer webhook) but
            // need different admin-facing labeling — see the branches below.
            $isCustomerActionable = $e instanceof GatewayCustomerActionRequiredException;
            $isConfigError = $e instanceof GatewayConfigurationException;

            error_log('[create_payin] gateway order failed: ' . $e->getMessage());
            write_audit_log($merchant['id'], 'payin_gateway_order_failed', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider'], 'reason' => $e->getMessage()]);
            // Excludes GatewayCustomerActionRequiredException on purpose —
            // that's the merchant's end-customer missing a phone number or
            // similar, not a gateway health problem admins need to see.
            if (!$isCustomerActionable) {
                $reason = mb_substr($e->getMessage(), 0, 120);
                notify_admins(
                    $pdo,
                    'gateway',
                    $isConfigError ? "{$gateway['display_name']} — secret/key configuration error" : "{$gateway['display_name']} rejected a payin",
                    $isConfigError
                        ? "This payin could not reach the gateway because its stored credentials could not be decrypted: {$reason} Source: our application (configuration)."
                        : "The gateway declined to create this order: {$reason} Source: payment gateway."
                );
            }

            $pdo->beginTransaction();
            try {
                $txnLock = $pdo->prepare(
                    'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, merchant_order_id, end_customer_name, end_customer_email, end_customer_phone
                     FROM transactions WHERE id = ? FOR UPDATE'
                );
                $txnLock->execute([$txnId]);
                $txnRow = $txnLock->fetch();
                if ($txnRow && $txnRow['status'] === 'pending') {
                    apply_transaction_outcome($pdo, $txnRow, 'failed', null);
                    release_gateway_reservation($pdo, $gatewayId, $amount);
                    $pdo->commit();
                    dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'failed']));
                } else {
                    $pdo->commit();
                }
            } catch (Throwable $e2) {
                $pdo->rollBack();
                error_log('[create_payin] failed to unwind gateway order failure: ' . $e2->getMessage());
            }

            return [
                'ok' => false,
                'status_code' => $isCustomerActionable ? 422 : 502,
                'message' => $isCustomerActionable
                    ? $e->getMessage()
                    : ($isConfigError
                        ? 'This payin could not be started due to a platform configuration issue. Please try again shortly.'
                        : 'This payin could not be started — the payment gateway rejected the request. Please try again.'),
                'data' => ['reference' => $reference, 'merchant_order_id' => $merchantOrderId],
            ];
        }
    } elseif ($isMockGateway) {
        // Explicitly admin-flagged mock/test gateway — the instant-success
        // dev/sandbox path (also what the API docs' "Try it" tester
        // exercises, via a gateway that's always sandbox_mode). Resolved
        // through the exact same apply_transaction_outcome() a real webhook
        // would use, so it gets a real timeline entry, gateway-outcome
        // recording, and a settlement notification instead of being
        // special-cased — and the customer callback fires for this outcome
        // too, same as a real gateway webhook would trigger.
        $pdo->beginTransaction();
        try {
            $txnLock = $pdo->prepare(
                'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, merchant_order_id, end_customer_name, end_customer_email, end_customer_phone
                 FROM transactions WHERE id = ? FOR UPDATE'
            );
            $txnLock->execute([$txnId]);
            $txnRow = $txnLock->fetch();
            if ($txnRow && $txnRow['status'] === 'pending') {
                apply_transaction_outcome($pdo, $txnRow, 'success', null);
                write_audit_log($merchant['id'], 'payin_sandbox_settled', 'transaction', $txnId, ['gateway_id' => $gatewayId]);
                $pdo->commit();
                $status = 'success';
                $message = 'PayIn completed.';
                dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'success']));
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[create_payin] failed to auto-settle sandbox payin: ' . $e->getMessage());
        }
    } else {
        // A real provider (razorpay/cashfree) was selected but isn't fully
        // configured for live payments (missing/invalid credentials) and is
        // NOT flagged is_mock — a genuine admin configuration problem. This
        // must never be silently treated as a mock success (that would mask
        // a real misconfiguration as a working payment) — unwind exactly
        // like a definite synchronous gateway rejection below does.
        error_log("[create_payin] gateway {$gatewayId} ({$gateway['provider']}) selected but not live-configured and not flagged is_mock");
        write_audit_log($merchant['id'], 'payin_gateway_misconfigured', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider']]);
        notify_admins(
            $pdo,
            'gateway',
            "{$gateway['display_name']} is misconfigured",
            "This gateway was selected for a payin but has no usable live {$gateway['provider']} credentials, and isn't flagged as a mock/test gateway. Source: our application (configuration)."
        );

        $pdo->beginTransaction();
        try {
            $txnLock = $pdo->prepare(
                'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, merchant_order_id, end_customer_name, end_customer_email, end_customer_phone
                 FROM transactions WHERE id = ? FOR UPDATE'
            );
            $txnLock->execute([$txnId]);
            $txnRow = $txnLock->fetch();
            if ($txnRow && $txnRow['status'] === 'pending') {
                apply_transaction_outcome($pdo, $txnRow, 'failed', null);
                release_gateway_reservation($pdo, $gatewayId, $amount);
                $pdo->commit();
                dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'failed']));
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[create_payin] failed to unwind misconfigured-gateway payin: ' . $e->getMessage());
        }

        return [
            'ok' => false,
            'status_code' => 503,
            'message' => 'PayIns are temporarily unavailable. Please try again shortly.',
            'data' => ['reference' => $reference, 'merchant_order_id' => $merchantOrderId],
        ];
    }

    return [
        'ok' => true,
        'status_code' => 200,
        'message' => $message,
        'data' => [
            'reference' => $reference,
            'status' => $status,
            'amount' => $amount,
            'fee' => $fee,
            'net_amount' => $net,
            'currency' => 'INR',
            'merchant_order_id' => $merchantOrderId,
            'payment_url' => $paymentUrl,
            'expires_at' => $expiresAt,
            'replayed' => false,
        ],
    ];
}

function get_payin_status(PDO $pdo, array $merchant, string $reference): array
{
    $stmt = $pdo->prepare(
        'SELECT reference, status, amount, fee, net_amount, currency, merchant_order_id, end_customer_name, end_customer_email, end_customer_phone, created_at, updated_at
         FROM transactions WHERE reference = ? AND user_id = ? AND type = "deposit"'
    );
    $stmt->execute([$reference, $merchant['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'status_code' => 404, 'message' => 'No payin found with that reference.', 'data' => null];
    }

    return ['ok' => true, 'status_code' => 200, 'message' => 'ok', 'data' => $row];
}
