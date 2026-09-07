<?php
/**
 * Provider-agnostic entry point for creating a real payment order.
 * Callers (deposits/create.php) don't need to know which provider a
 * gateway is - they call create_gateway_order() and handle exactly two
 * exception types, same as before this file existed. Adding a third
 * provider means adding a case here, not touching the caller.
 */

require_once __DIR__ . '/razorpay.php';
require_once __DIR__ . '/cashfree.php';

/** Thrown when we don't know if the order was actually created - never treat as a definite failure. */
class GatewayOrderAmbiguousException extends RuntimeException {}

/** A definite failure whose message is safe and useful to show the customer verbatim (e.g. "add a phone number") - unlike an opaque provider rejection reason, which callers should NOT surface as-is. */
class GatewayCustomerActionRequiredException extends RuntimeException {}

function gateway_supports_live_order_creation(array $gateway): bool
{
    return in_array($gateway['provider'], ['razorpay', 'cashfree'], true)
        && !empty($gateway['public_key'])
        && !empty($gateway['api_key_encrypted']);
}

/**
 * Same gateway pool selects for both directions (see
 * includes/gateway_selector.php - selection has no concept of "this
 * gateway is payin-only"), so a gateway can be live-order-capable without
 * being live-payout-capable, or vice versa. Razorpay additionally needs
 * payout_account_number (the RazorpayX source account) - not required for
 * live order creation, so a gateway can pass one check and fail the other.
 */
function gateway_supports_live_payout(array $gateway): bool
{
    if (!in_array($gateway['provider'], ['razorpay', 'cashfree'], true)
        || empty($gateway['public_key']) || empty($gateway['api_key_encrypted'])) {
        return false;
    }
    if ($gateway['provider'] === 'razorpay' && empty($gateway['payout_account_number'])) {
        return false;
    }
    return true;
}

/**
 * Creates a real order at whichever provider this gateway is.
 *
 * @return array{gateway_txn_id: string, checkout: array} checkout is a
 *   provider-shaped payload the frontend uses to launch that provider's
 *   widget - always includes a "provider" key so the frontend can branch.
 * @throws GatewayOrderAmbiguousException see class doc.
 * @throws RuntimeException on a definite, synchronous rejection.
 */
function create_gateway_order(array $gateway, string $reference, string $amountRupees, array $user, ?string $customerPhone): array
{
    switch ($gateway['provider']) {
        case 'razorpay':
            try {
                $order = razorpay_create_order($gateway, $reference, $amountRupees, 'INR');
            } catch (RazorpayAmbiguousException $e) {
                throw new GatewayOrderAmbiguousException($e->getMessage(), 0, $e);
            }
            return [
                'gateway_txn_id' => $order['order_id'],
                'checkout' => [
                    'provider' => 'razorpay',
                    'order_id' => $order['order_id'],
                    'key_id' => $order['key_id'],
                    'amount' => $order['amount_paise'],
                    'currency' => 'INR',
                ],
            ];

        case 'cashfree':
            if (empty($customerPhone)) {
                throw new GatewayCustomerActionRequiredException('Your profile is missing a phone number, which Cashfree requires to start a payment. Add one in Settings and try again.');
            }
            try {
                $order = cashfree_create_order($gateway, $reference, $amountRupees, [
                    'id' => 'user' . $user['id'],
                    'phone' => $customerPhone,
                    'email' => $user['email'],
                    'name' => $user['name'],
                ], 'INR');
            } catch (CashfreeAmbiguousException $e) {
                throw new GatewayOrderAmbiguousException($e->getMessage(), 0, $e);
            }
            return [
                'gateway_txn_id' => $order['cf_order_id'] ?? $order['order_id'],
                'checkout' => [
                    'provider' => 'cashfree',
                    'payment_session_id' => $order['payment_session_id'],
                    'order_id' => $order['order_id'],
                    'environment' => !empty($gateway['sandbox_mode']) ? 'sandbox' : 'production',
                ],
            ];

        default:
            throw new RuntimeException("No live integration exists for provider '{$gateway['provider']}'.");
    }
}

/**
 * Creates a real payout at whichever provider this gateway is — the
 * withdrawal-side counterpart to create_gateway_order() above. See that
 * function's docblock for the shared exception-handling contract.
 *
 * @param array $bank must have 'account_holder', 'account_number', 'ifsc_code' — the destination bank account (settlement_banks row).
 * @return array{gateway_txn_id: string, provider: string}
 * @throws GatewayOrderAmbiguousException see class doc.
 * @throws GatewayCustomerActionRequiredException see class doc.
 * @throws RuntimeException on a definite, synchronous rejection.
 */
function create_gateway_payout(array $gateway, string $reference, string $amountRupees, array $bank, array $user, ?string $customerPhone, ?string $customerAddress): array
{
    switch ($gateway['provider']) {
        case 'razorpay':
            try {
                $payout = razorpay_create_payout($gateway, $reference, $amountRupees, $bank);
            } catch (RazorpayAmbiguousException $e) {
                throw new GatewayOrderAmbiguousException($e->getMessage(), 0, $e);
            }
            return ['gateway_txn_id' => $payout['payout_id'], 'provider' => 'razorpay'];

        case 'cashfree':
            if (empty($customerPhone) || empty($customerAddress)) {
                throw new GatewayCustomerActionRequiredException('Your profile is missing a phone number or office address, which Cashfree requires to process a payout. Add them in Settings and try again.');
            }
            try {
                $payout = cashfree_create_payout($gateway, $reference, $amountRupees, $bank, [
                    'id' => 'user' . $user['id'],
                    'phone' => $customerPhone,
                    'address' => $customerAddress,
                ]);
            } catch (CashfreeAmbiguousException $e) {
                throw new GatewayOrderAmbiguousException($e->getMessage(), 0, $e);
            }
            return ['gateway_txn_id' => $payout['transfer_id'], 'provider' => 'cashfree'];

        default:
            throw new RuntimeException("No live payout integration exists for provider '{$gateway['provider']}'.");
    }
}

/**
 * Reconciliation only (includes/reconciliation.php) — checks a gateway
 * directly for a transaction's CURRENT status, for a pending transaction
 * that never got a webhook. Never throws: any network/parsing failure or
 * unsupported provider returns 'unknown', meaning "still don't know" —
 * reconciliation must leave the transaction pending rather than ever
 * guessing an outcome (see FR-010 / includes/payin_service.php).
 *
 * @param string $type 'deposit' (payin) or 'withdrawal' (payout) — the
 *   transactions.type value, not the merchant-facing payin/payout name.
 * @return string one of 'success', 'failed', 'pending', 'unknown'
 */
function check_gateway_transaction_status(array $gateway, string $type, string $gatewayTxnId): string
{
    try {
        return match ($gateway['provider']) {
            'razorpay' => $type === 'deposit'
                ? razorpay_fetch_payin_status($gateway, $gatewayTxnId)
                : razorpay_fetch_payout_status($gateway, $gatewayTxnId),
            'cashfree' => $type === 'deposit'
                ? cashfree_fetch_payin_status($gateway, $gatewayTxnId)
                : cashfree_fetch_payout_status($gateway, $gatewayTxnId),
            default => 'unknown',
        };
    } catch (Throwable $e) {
        error_log('[reconciliation] status check failed for gateway ' . ($gateway['id'] ?? '?') . ': ' . $e->getMessage());
        return 'unknown';
    }
}
