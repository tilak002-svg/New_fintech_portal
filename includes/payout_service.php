<?php
/**
 * Merchant-facing PayOut: the caller (a merchant, authenticated via
 * api_guard()) disburses money from their own settlement-ledger balance
 * (see includes/deposit_service.php-adjacent note in payin_service.php) to
 * a beneficiary they specify per request. Adapted from
 * withdrawal_service.php — reuses the same balance check, gateway
 * selection/reservation, and exception-triage machinery verbatim.
 *
 * Deliberately does NOT look up settlement_banks — that table is the
 * merchant's OWN bank account, reserved for a future Settlements feature,
 * not the source of an arbitrary per-request beneficiary. The beneficiary
 * bank details come entirely from $input.
 *
 * Same return convention as withdrawal_service.php:
 *   ['ok' => bool, 'status_code' => int, 'message' => string, 'data' => array|null]
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/gateway_selector.php';
require_once __DIR__ . '/gateway_webhooks.php';
require_once __DIR__ . '/gateway_providers/dispatch.php';
require_once __DIR__ . '/customer_webhooks.php';

function create_payout(PDO $pdo, array $merchant, array $input, bool $sandboxOnly = false): array
{
    $merchantOrderId = trim((string) ($input['merchant_order_id'] ?? ''));
    if ($merchantOrderId === '' || mb_strlen($merchantOrderId) > 120) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'merchant_order_id is required (max 120 characters).', 'data' => null];
    }

    $idempotencyKey = 'PO:' . $merchant['id'] . ':' . $merchantOrderId;
    $existingStmt = $pdo->prepare(
        'SELECT reference, status, amount, fee, net_amount, merchant_order_id FROM transactions WHERE idempotency_key = ?'
    );
    $existingStmt->execute([$idempotencyKey]);
    $existing = $existingStmt->fetch();
    if ($existing) {
        return [
            'ok' => true,
            'status_code' => 200,
            'message' => 'Replayed: a payout with this merchant_order_id already exists.',
            'data' => [
                'reference' => $existing['reference'],
                'status' => $existing['status'],
                'amount' => $existing['amount'],
                'fee' => $existing['fee'],
                'net_amount' => $existing['net_amount'],
                'merchant_order_id' => $existing['merchant_order_id'],
                'replayed' => true,
            ],
        ];
    }

    $currency = strtoupper(trim((string) ($input['currency'] ?? 'INR')));
    if ($currency !== 'INR') {
        return ['ok' => false, 'status_code' => 422, 'message' => 'Only INR is supported at this time.', 'data' => null];
    }

    $amount = sanitize_amount($input['amount'] ?? null);
    if ($amount === null || money_cmp($amount, '20.00') < 0) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'Enter an amount of at least ₹20.00.', 'data' => null];
    }

    // All beneficiary fields are required unconditionally — gateway
    // selection isn't caller-controlled, so the API's required-field set
    // can't depend on which gateway happens to be picked (same reasoning
    // as payin_service.php's end_customer_phone requirement).
    $beneficiaryName = trim((string) ($input['beneficiary_name'] ?? ''));
    $beneficiaryAccountNumber = trim((string) ($input['beneficiary_account_number'] ?? ''));
    $beneficiaryIfsc = trim((string) ($input['beneficiary_ifsc'] ?? ''));
    $beneficiaryBankName = trim((string) ($input['beneficiary_bank_name'] ?? ''));
    $beneficiaryPhone = trim((string) ($input['beneficiary_phone'] ?? ''));
    $beneficiaryAddress = trim((string) ($input['beneficiary_address'] ?? ''));

    $required = [
        'beneficiary_name' => $beneficiaryName,
        'beneficiary_account_number' => $beneficiaryAccountNumber,
        'beneficiary_ifsc' => $beneficiaryIfsc,
        'beneficiary_bank_name' => $beneficiaryBankName,
        'beneficiary_phone' => $beneficiaryPhone,
        'beneficiary_address' => $beneficiaryAddress,
    ];
    foreach ($required as $field => $value) {
        if ($value === '') {
            return ['ok' => false, 'status_code' => 422, 'message' => "{$field} is required.", 'data' => null];
        }
    }
    if (mb_strlen($beneficiaryName) > 120 || mb_strlen($beneficiaryAccountNumber) > 40
        || mb_strlen($beneficiaryIfsc) > 20 || mb_strlen($beneficiaryBankName) > 120
        || mb_strlen($beneficiaryPhone) > 20 || mb_strlen($beneficiaryAddress) > 255) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'One or more beneficiary fields exceed the maximum length.', 'data' => null];
    }

    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT available_balance FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$merchant['id']]);
        $wallet = $walletStmt->fetch();
        $available = $wallet['available_balance'] ?? '0.00';

        $fee = calculate_fee('withdrawal', 'API', $amount);
        $totalDeducted = money_add($amount, $fee);

        if (money_cmp($totalDeducted, $available) > 0) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Insufficient available balance for this payout plus the ' . money_format($fee) . ' fee.',
                'data' => ['available_balance' => $available],
            ];
        }

        $net = money_sub($amount, $fee);
        $reference = generate_reference('withdrawal');

        // Reserved against the net payout amount — Verapay's fee is
        // retained before the payout gateway ever sees the transfer, same
        // rule as withdrawal_service.php::create_withdrawal().
        $selection = select_and_reserve_gateway($pdo, (int) $merchant['id'], $net, $sandboxOnly, 'payout');
        if ($selection['gateway'] === null) {
            $pdo->rollBack();
            write_audit_log($merchant['id'], 'payout_gateway_unavailable', 'transaction', null, ['amount' => $amount, 'merchant_order_id' => $merchantOrderId, 'reason' => $selection['reason']]);
            if (!$sandboxOnly) {
                $isNoCapacity = $selection['reason'] === 'no_eligible_gateway';
                notify_admins(
                    $pdo,
                    'gateway',
                    $isNoCapacity ? 'PayOut blocked: every gateway is paused or over its limit' : 'PayOut blocked: no active payment gateway',
                    $isNoCapacity
                        ? "A payout for {$net} INR could not be routed — every active gateway is either auto-paused or would exceed a configured daily/hourly/monthly/per-transaction limit. Source: limit reached."
                        : 'A payout could not be routed — no payment gateway is active. Source: our application (configuration).'
                );
            }
            $message = $sandboxOnly
                ? 'No sandbox-mode gateway is currently configured. Ask your platform admin to enable one for testing.'
                : 'PayOuts are temporarily unavailable. Please try again shortly.';
            return ['ok' => false, 'status_code' => 503, 'message' => $message, 'data' => null];
        }
        $gateway = $selection['gateway'];
        $gatewayId = (int) $gateway['id'];
        // Distinct from gateway_supports_live_payout($gateway) below: a
        // gateway an admin explicitly flagged as mock/test vs. a real
        // razorpay/cashfree row that simply isn't fully configured yet —
        // see the three-way branch further down.
        $isMockGateway = !empty($gateway['is_mock']);

        $destination = $beneficiaryBankName . ' ••' . substr($beneficiaryAccountNumber, -4);

        $insert = $pdo->prepare(
            'INSERT INTO transactions (user_id, type, method, amount, fee, net_amount, currency, status, reference, destination, gateway_id, idempotency_key, merchant_order_id, beneficiary_name, beneficiary_account_number, beneficiary_ifsc, beneficiary_bank_name, beneficiary_phone, beneficiary_address)
             VALUES (?, "withdrawal", "API", ?, ?, ?, "INR", "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$merchant['id'], $amount, $fee, $net, $reference, $destination, $gatewayId, $idempotencyKey, $merchantOrderId, $beneficiaryName, $beneficiaryAccountNumber, $beneficiaryIfsc, $beneficiaryBankName, $beneficiaryPhone, $beneficiaryAddress]);
        $txnId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE wallets SET available_balance = available_balance - ?, pending_balance = pending_balance + ? WHERE user_id = ?')
            ->execute([$totalDeducted, $totalDeducted, $merchant['id']]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, "withdrawal", ?, ?)')
            ->execute([$merchant['id'], 'PayOut submitted', "A payout {$reference} for " . money_format($amount) . ' is being processed.']);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[create_payout] ' . $e->getMessage());
        return ['ok' => false, 'status_code' => 500, 'message' => 'Unable to process this payout right now. Please try again.', 'data' => null];
    }

    write_audit_log($merchant['id'], 'payout_created', 'transaction', $txnId, ['amount' => $amount, 'merchant_order_id' => $merchantOrderId, 'gateway_id' => $gatewayId]);

    $message = 'PayOut submitted and pending settlement.';
    $status = 'pending';

    if (gateway_supports_live_payout($gateway)) {
        $bank = [
            'account_holder' => $beneficiaryName,
            'account_number' => $beneficiaryAccountNumber,
            'ifsc_code' => $beneficiaryIfsc,
        ];

        // Cashfree beneficiary-id fix: MUST NOT pass the merchant's own id
        // here. cashfree_create_payout() derives its beneficiary_id as
        // 'vpuser' . $customer['id'] — if every payout for this merchant
        // passed the merchant's id, two different beneficiaries would
        // silently collide on the same Cashfree beneficiary record,
        // sending money to whichever was registered most recently. Instead
        // derive a stable id from the beneficiary's own details, so each
        // distinct beneficiary gets its own Cashfree record.
        $beneficiaryKey = substr(hash('sha256', $merchant['id'] . '|' . $beneficiaryAccountNumber . '|' . $beneficiaryIfsc), 0, 20);

        try {
            $payoutResult = create_gateway_payout($gateway, $reference, $amount, $bank, ['id' => $beneficiaryKey], $beneficiaryPhone, $beneficiaryAddress);
            $pdo->prepare('UPDATE transactions SET gateway_txn_id = ? WHERE id = ?')->execute([$payoutResult['gateway_txn_id'], $txnId]);
            write_audit_log($merchant['id'], 'payout_gateway_request_sent', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider']]);
            $message = 'This payout is being processed by the payment gateway.';
        } catch (GatewayOrderAmbiguousException $e) {
            // Never known whether the provider actually created the payout
            // — never auto-retry on a different gateway.
            error_log('[create_payout] gateway payout ambiguous: ' . $e->getMessage());
            write_audit_log($merchant['id'], 'payout_gateway_payout_ambiguous', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider'], 'reason' => $e->getMessage()]);
            notify_admins(
                $pdo,
                'gateway',
                "{$gateway['display_name']} — could not confirm payout creation",
                'A payout request timed out or failed at the network level before this gateway confirmed it — money may or may not have moved. Source: connectivity (not a definite provider or application error).'
            );
            $message = 'PayOut submitted, but we could not confirm the payment gateway accepted it yet. This will update automatically once confirmed.';
        } catch (Throwable $e) {
            $isCustomerActionable = $e instanceof GatewayCustomerActionRequiredException;
            $isConfigError = $e instanceof GatewayConfigurationException;

            error_log('[create_payout] gateway payout failed: ' . $e->getMessage());
            write_audit_log($merchant['id'], 'payout_gateway_payout_failed', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider'], 'reason' => $e->getMessage()]);
            if (!$isCustomerActionable) {
                $reason = mb_substr($e->getMessage(), 0, 120);
                notify_admins(
                    $pdo,
                    'gateway',
                    $isConfigError ? "{$gateway['display_name']} — secret/key configuration error" : "{$gateway['display_name']} rejected a payout",
                    $isConfigError
                        ? "This payout could not reach the gateway because its stored credentials could not be decrypted: {$reason} Source: our application (configuration)."
                        : "The gateway declined to create this payout: {$reason} Source: payment gateway."
                );
            }

            $pdo->beginTransaction();
            try {
                $txnLock = $pdo->prepare(
                    'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, merchant_order_id, beneficiary_name, beneficiary_account_number, beneficiary_ifsc, beneficiary_bank_name
                     FROM transactions WHERE id = ? FOR UPDATE'
                );
                $txnLock->execute([$txnId]);
                $txnRow = $txnLock->fetch();
                if ($txnRow && $txnRow['status'] === 'pending') {
                    apply_transaction_outcome($pdo, $txnRow, 'failed', null);
                    release_gateway_reservation($pdo, $gatewayId, $net);
                    $pdo->commit();
                    dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'failed']));
                } else {
                    $pdo->commit();
                }
            } catch (Throwable $e2) {
                $pdo->rollBack();
                error_log('[create_payout] failed to unwind gateway payout failure: ' . $e2->getMessage());
            }

            return [
                'ok' => false,
                'status_code' => $isCustomerActionable ? 422 : 502,
                'message' => $isCustomerActionable
                    ? $e->getMessage()
                    : ($isConfigError
                        ? 'This payout could not be started due to a platform configuration issue. Please try again shortly.'
                        : 'This payout could not be started — the payment gateway rejected the request. Please try again.'),
                'data' => ['reference' => $reference, 'merchant_order_id' => $merchantOrderId],
            ];
        }
    } elseif ($isMockGateway) {
        // Explicitly admin-flagged mock/test gateway — the instant-success
        // dev/sandbox path (mirrors create_payin()'s equivalent branch).
        // Without this, a sandbox-mode payout had no path to resolution at
        // all: no real gateway call was ever made, so no webhook could
        // ever arrive, and it would sit 'pending' forever.
        $pdo->beginTransaction();
        try {
            $txnLock = $pdo->prepare(
                'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, merchant_order_id, beneficiary_name, beneficiary_account_number, beneficiary_ifsc, beneficiary_bank_name
                 FROM transactions WHERE id = ? FOR UPDATE'
            );
            $txnLock->execute([$txnId]);
            $txnRow = $txnLock->fetch();
            if ($txnRow && $txnRow['status'] === 'pending') {
                apply_transaction_outcome($pdo, $txnRow, 'success', null);
                write_audit_log($merchant['id'], 'payout_sandbox_settled', 'transaction', $txnId, ['gateway_id' => $gatewayId]);
                $pdo->commit();
                $status = 'success';
                $message = 'PayOut completed.';
                dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'success']));
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[create_payout] failed to auto-settle sandbox payout: ' . $e->getMessage());
        }
    } else {
        // A real provider (razorpay/cashfree) was selected but isn't fully
        // configured for live payouts (missing/invalid credentials) and is
        // NOT flagged is_mock — a genuine admin configuration problem. Must
        // never be silently treated as a mock success — unwind exactly like
        // a definite synchronous gateway rejection above does.
        error_log("[create_payout] gateway {$gatewayId} ({$gateway['provider']}) selected but not live-configured and not flagged is_mock");
        write_audit_log($merchant['id'], 'payout_gateway_misconfigured', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider']]);
        notify_admins(
            $pdo,
            'gateway',
            "{$gateway['display_name']} is misconfigured",
            "This gateway was selected for a payout but has no usable live {$gateway['provider']} credentials, and isn't flagged as a mock/test gateway. Source: our application (configuration)."
        );

        $pdo->beginTransaction();
        try {
            $txnLock = $pdo->prepare(
                'SELECT id, user_id, type, status, amount, fee, net_amount, currency, reference, gateway_id, merchant_order_id, beneficiary_name, beneficiary_account_number, beneficiary_ifsc, beneficiary_bank_name
                 FROM transactions WHERE id = ? FOR UPDATE'
            );
            $txnLock->execute([$txnId]);
            $txnRow = $txnLock->fetch();
            if ($txnRow && $txnRow['status'] === 'pending') {
                apply_transaction_outcome($pdo, $txnRow, 'failed', null);
                release_gateway_reservation($pdo, $gatewayId, $net);
                $pdo->commit();
                dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'failed']));
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[create_payout] failed to unwind misconfigured-gateway payout: ' . $e->getMessage());
        }

        return [
            'ok' => false,
            'status_code' => 503,
            'message' => 'PayOuts are temporarily unavailable. Please try again shortly.',
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
            'replayed' => false,
        ],
    ];
}

function get_payout_status(PDO $pdo, array $merchant, string $reference): array
{
    $stmt = $pdo->prepare(
        'SELECT reference, status, amount, fee, net_amount, currency, merchant_order_id, beneficiary_name, beneficiary_bank_name, created_at, updated_at
         FROM transactions WHERE reference = ? AND user_id = ? AND type = "withdrawal"'
    );
    $stmt->execute([$reference, $merchant['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'status_code' => 404, 'message' => 'No payout found with that reference.', 'data' => null];
    }

    return ['ok' => true, 'status_code' => 200, 'message' => 'ok', 'data' => $row];
}
