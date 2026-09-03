<?php
/**
 * Shared withdrawal logic, called by public/api/withdrawals/create.php.
 * Accepts an optional idempotency key for future callers (e.g. a partner
 * API) that need replay protection — the browser flow never passes one.
 * See deposit_service.php for the return-array convention this follows.
 */

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/gateway_selector.php';
require_once __DIR__ . '/gateway_webhooks.php';
require_once __DIR__ . '/gateway_providers/dispatch.php';
require_once __DIR__ . '/customer_webhooks.php';

function create_withdrawal(PDO $pdo, array $user, $rawAmount, $rawDestination, ?string $idempotencyKey = null): array
{
    if ($idempotencyKey !== null) {
        $existingStmt = $pdo->prepare(
            'SELECT reference, status, amount, fee, net_amount FROM transactions WHERE idempotency_key = ?'
        );
        $existingStmt->execute([$idempotencyKey]);
        $existing = $existingStmt->fetch();
        if ($existing) {
            return [
                'ok' => true,
                'status_code' => 200,
                'message' => 'Replayed: a withdrawal with this idempotency key already exists.',
                'data' => [
                    'reference' => $existing['reference'],
                    'status' => $existing['status'],
                    'amount' => $existing['amount'],
                    'fee' => $existing['fee'],
                    'net_amount' => $existing['net_amount'],
                    'replayed' => true,
                ],
            ];
        }
    }

    $amount = sanitize_amount($rawAmount);
    $destination = trim((string) ($rawDestination ?? ''));

    if ($amount === null || money_cmp($amount, '20.00') < 0) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'Enter an amount of at least ₹20.00.', 'data' => null];
    }
    if ($destination === '' || mb_strlen($destination) > 190) {
        return ['ok' => false, 'status_code' => 422, 'message' => 'Select a valid withdrawal destination.', 'data' => null];
    }

    $pdo->beginTransaction();
    try {
        $walletStmt = $pdo->prepare('SELECT available_balance FROM wallets WHERE user_id = ? FOR UPDATE');
        $walletStmt->execute([$user['id']]);
        $wallet = $walletStmt->fetch();
        $available = $wallet['available_balance'] ?? '0.00';

        $fee = calculate_fee('withdrawal', 'Bank transfer', $amount);
        $totalDeducted = money_add($amount, $fee);

        if (money_cmp($totalDeducted, $available) > 0) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Insufficient available balance for this withdrawal plus the ' . money_format($fee) . ' fee.',
                'data' => ['available_balance' => $available],
            ];
        }

        $net = money_sub($amount, $fee);
        $reference = generate_reference('withdrawal');

        // Capacity is reserved against the net payout amount — Verapay's
        // fee is retained before the payout gateway ever sees the
        // transfer, so that's the figure that actually consumes the
        // gateway's daily limit.
        $selection = select_and_reserve_gateway($pdo, $net);
        if ($selection['gateway'] === null) {
            $pdo->rollBack();
            write_audit_log($user['id'], 'withdrawal_gateway_unavailable', 'transaction', null, ['amount' => $amount, 'net_amount' => $net, 'reason' => $selection['reason']]);
            return ['ok' => false, 'status_code' => 503, 'message' => 'Withdrawals are temporarily unavailable. Please try again shortly.', 'data' => null];
        }
        $gateway = $selection['gateway'];
        $gatewayId = (int) $gateway['id'];

        $insert = $pdo->prepare(
            'INSERT INTO transactions (user_id, type, method, amount, fee, net_amount, currency, status, reference, destination, gateway_id, idempotency_key)
             VALUES (?, "withdrawal", "Bank transfer", ?, ?, ?, "INR", "pending", ?, ?, ?, ?)'
        );
        $insert->execute([$user['id'], $amount, $fee, $net, $reference, $destination, $gatewayId, $idempotencyKey]);
        $txnId = (int) $pdo->lastInsertId();

        // Hold the funds: move out of available into pending until settled.
        $pdo->prepare('UPDATE wallets SET available_balance = available_balance - ?, pending_balance = pending_balance + ? WHERE user_id = ?')
            ->execute([$totalDeducted, $totalDeducted, $user['id']]);

        $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?, "withdrawal", ?, ?)')
            ->execute([$user['id'], 'Withdrawal submitted', "Your withdrawal request {$reference} for " . money_format($amount) . ' is being processed.']);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[create_withdrawal] ' . $e->getMessage());
        return ['ok' => false, 'status_code' => 500, 'message' => 'Unable to process your withdrawal right now. Please try again.', 'data' => null];
    }

    write_audit_log($user['id'], 'withdrawal_created', 'transaction', $txnId, ['amount' => $amount, 'destination' => $destination, 'gateway_id' => $gatewayId]);

    // The outbound call to the provider happens only now, after the DB
    // transaction has committed — never make a network call while holding
    // the wallet/usage row locks above. Mirrors deposit_service.php's
    // create_deposit() — see that function for why each exception type is
    // handled the way it is.
    $message = 'Withdrawal submitted and pending settlement.';

    if (gateway_supports_live_payout($gateway)) {
        $bankStmt = $pdo->prepare('SELECT account_holder, account_number, ifsc_code FROM settlement_banks WHERE user_id = ?');
        $bankStmt->execute([$user['id']]);
        $bank = $bankStmt->fetch();

        $profileStmt = $pdo->prepare('SELECT mobile_number, office_address FROM business_profiles WHERE user_id = ?');
        $profileStmt->execute([$user['id']]);
        $profile = $profileStmt->fetch() ?: [];

        if (!$bank) {
            // No money has moved and nothing was reserved beyond the
            // in-app hold — safe to unwind immediately, same as a
            // synchronous provider rejection below.
            $pdo->beginTransaction();
            try {
                $txnLock = $pdo->prepare('SELECT id, user_id, type, status, amount, fee, net_amount, gateway_id FROM transactions WHERE id = ? FOR UPDATE');
                $txnLock->execute([$txnId]);
                $txnRow = $txnLock->fetch();
                if ($txnRow && $txnRow['status'] === 'pending') {
                    apply_transaction_outcome($pdo, $txnRow, 'failed', null);
                    release_gateway_reservation($pdo, $gatewayId, $net);
                    $pdo->commit();
                    dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'failed', 'reference' => $reference, 'currency' => 'INR']));
                } else {
                    $pdo->commit();
                }
            } catch (Throwable $e2) {
                $pdo->rollBack();
                error_log('[create_withdrawal] failed to unwind missing-bank-details failure: ' . $e2->getMessage());
            }

            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Add your bank account details in Settings before requesting a withdrawal.',
                'data' => ['reference' => $reference],
            ];
        }

        try {
            $payoutResult = create_gateway_payout($gateway, $reference, $amount, $bank, $user, $profile['mobile_number'] ?? null, $profile['office_address'] ?? null);
            $pdo->prepare('UPDATE transactions SET gateway_txn_id = ? WHERE id = ?')->execute([$payoutResult['gateway_txn_id'], $txnId]);
            $message = 'Your withdrawal is being processed by the payment gateway.';
        } catch (GatewayOrderAmbiguousException $e) {
            // We do not know if the provider actually created the payout —
            // never auto-retry on a different gateway here. The
            // transaction stays pending with no payout id; only a webhook
            // (or manual admin reconciliation) can resolve it from here.
            error_log('[create_withdrawal] gateway payout ambiguous: ' . $e->getMessage());
            write_audit_log($user['id'], 'withdrawal_gateway_payout_ambiguous', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider'], 'reason' => $e->getMessage()]);
            $message = 'Withdrawal submitted, but we could not confirm the payment gateway accepted it yet. This will update automatically once confirmed.';
        } catch (Throwable $e) {
            // A definite, synchronous rejection — safe to unwind the
            // reservation and mark this attempt failed, same as deposits.
            $isCustomerActionable = $e instanceof GatewayCustomerActionRequiredException;

            error_log('[create_withdrawal] gateway payout failed: ' . $e->getMessage());
            write_audit_log($user['id'], 'withdrawal_gateway_payout_failed', 'transaction', $txnId, ['gateway_id' => $gatewayId, 'provider' => $gateway['provider'], 'reason' => $e->getMessage()]);

            $pdo->beginTransaction();
            try {
                $txnLock = $pdo->prepare('SELECT id, user_id, type, status, amount, fee, net_amount, gateway_id FROM transactions WHERE id = ? FOR UPDATE');
                $txnLock->execute([$txnId]);
                $txnRow = $txnLock->fetch();
                if ($txnRow && $txnRow['status'] === 'pending') {
                    apply_transaction_outcome($pdo, $txnRow, 'failed', null);
                    release_gateway_reservation($pdo, $gatewayId, $net);
                    $pdo->commit();
                    dispatch_customer_transaction_webhook($pdo, array_merge($txnRow, ['status' => 'failed', 'reference' => $reference, 'currency' => 'INR']));
                } else {
                    $pdo->commit();
                }
            } catch (Throwable $e2) {
                $pdo->rollBack();
                error_log('[create_withdrawal] failed to unwind gateway payout failure: ' . $e2->getMessage());
            }

            return [
                'ok' => false,
                'status_code' => $isCustomerActionable ? 422 : 502,
                'message' => $isCustomerActionable ? $e->getMessage() : 'This withdrawal could not be started — the payment gateway rejected the request. Please try again.',
                'data' => ['reference' => $reference],
            ];
        }
    }

    return [
        'ok' => true,
        'status_code' => 200,
        'message' => $message,
        'data' => [
            'reference' => $reference,
            'status' => 'pending',
            'amount' => $amount,
            'fee' => $fee,
            'net_amount' => $net,
            'replayed' => false,
        ],
    ];
}