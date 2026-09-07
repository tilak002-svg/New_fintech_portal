<?php
/**
 * Standalone page (no sidebar/navbar chrome, no auth) — this is where a
 * MERCHANT'S END-CUSTOMER lands to pay, never the merchant themselves and
 * never someone with a Verapay login. Gated entirely by the unguessable
 * ?session= token from payment_sessions.
 *
 * This is the ONLY place a provider's checkout widget (Razorpay
 * checkout.js / Cashfree Drop-in) and its raw order_id/key_id/
 * payment_session_id are ever exposed to a browser — the merchant's own
 * PayIn API response never contains them (see includes/payin_service.php).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$sessionToken = trim((string) ($_GET['session'] ?? ''));
$row = null;

if ($sessionToken !== '' && preg_match('/^[0-9a-f]{64}$/', $sessionToken)) {
    $stmt = db()->prepare(
        'SELECT ps.status AS session_status, ps.checkout_payload, ps.return_url, ps.expires_at,
                t.reference, t.amount, t.currency, t.status AS txn_status
         FROM payment_sessions ps
         JOIN transactions t ON t.id = ps.transaction_id
         WHERE ps.session_token = ?'
    );
    $stmt->execute([$sessionToken]);
    $row = $stmt->fetch();
}

$expired = !$row || $row['session_status'] !== 'created' || strtotime($row['expires_at']) <= time();
$checkout = ($row && !$expired) ? json_decode($row['checkout_payload'], true) : null;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete payment · Verapay</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/app.build.css">
</head>
<body class="min-h-screen antialiased bg-surface-muted">
    <div class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="flex items-center justify-center gap-2.5 mb-8">
                <span class="flex items-center shrink-0" aria-hidden="true"><?= brand_mark('h-8 w-auto') ?></span>
                <span class="text-3xl font-bold text-text-primary tracking-tight">Verapay</span>
            </div>

            <div class="card" id="checkout-card">
                <?php if (!$row || $expired): ?>
                    <div class="text-center py-4">
                        <span class="icon-chip-md icon-chip-danger mx-auto mb-4"><?= icon('alert-circle', 'w-5 h-5') ?></span>
                        <h1 class="text-3xl font-semibold text-text-primary mb-1.5">This payment link has expired</h1>
                        <p class="text-md text-text-secondary">Ask the merchant to start a new payment.</p>
                    </div>
                <?php elseif ($row['txn_status'] !== 'pending'): ?>
                    <div class="text-center py-4">
                        <span class="icon-chip-md icon-chip-success mx-auto mb-4"><?= icon('deposit', 'w-5 h-5') ?></span>
                        <h1 class="text-3xl font-semibold text-text-primary mb-1.5">
                            <?= $row['txn_status'] === 'success' ? 'Payment received' : 'Payment already resolved' ?>
                        </h1>
                        <p class="text-md text-text-secondary">Reference <?= e($row['reference']) ?></p>
                    </div>
                <?php else: ?>
                    <h1 class="text-3xl font-semibold text-text-primary mb-1.5">Complete your payment</h1>
                    <p class="text-md text-text-secondary mb-6">Reference <?= e($row['reference']) ?></p>

                    <div class="flex items-baseline justify-between mb-6 pb-6 border-b border-border">
                        <span class="text-md text-text-secondary">Amount due</span>
                        <span class="text-4xl font-semibold text-text-primary"><?= e(money_format($row['amount'], $row['currency'])) ?></span>
                    </div>

                    <button type="button" id="pay-button" class="btn-primary w-full">Pay now</button>
                    <p id="pay-status" class="text-sm text-text-secondary text-center mt-4" role="status" aria-live="polite"></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($row && !$expired && $row['txn_status'] === 'pending'): ?>
    <script>
    (function () {
        'use strict';
        var checkout = <?= json_encode($checkout) ?>;
        var sessionToken = <?= json_encode($sessionToken) ?>;
        var returnUrl = <?= json_encode($row['return_url']) ?>;
        var reference = <?= json_encode($row['reference']) ?>;
        var payButton = document.getElementById('pay-button');
        var statusEl = document.getElementById('pay-status');

        function loadScript(src) {
            return new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = function () { reject(new Error('Could not load the payment widget.')); };
                document.head.appendChild(script);
            });
        }

        function finish(status) {
            var target = returnUrl
                ? returnUrl + (returnUrl.indexOf('?') === -1 ? '?' : '&') + 'reference=' + encodeURIComponent(reference) + '&status=' + encodeURIComponent(status)
                : null;
            if (target) {
                window.location.href = target;
                return;
            }
            statusEl.textContent = status === 'success'
                ? 'Payment confirmed. You may close this window.'
                : 'This payment did not complete. You may close this window.';
        }

        function pollUntilResolved() {
            statusEl.textContent = 'Confirming with the payment gateway…';
            var attempts = 0;
            var interval = setInterval(function () {
                attempts += 1;
                fetch('/api/v1/payins/session-status.php?session=' + encodeURIComponent(sessionToken))
                    .then(function (res) { return res.json(); })
                    .then(function (body) {
                        var status = body && body.data ? body.data.status : null;
                        if (status === 'success' || status === 'failed') {
                            clearInterval(interval);
                            finish(status);
                        } else if (attempts >= 30) {
                            clearInterval(interval);
                            statusEl.textContent = 'Still confirming — you can safely close this window; the merchant will be notified once it settles.';
                        }
                    })
                    .catch(function () { /* keep polling on transient network errors */ });
            }, 2000);
        }

        function payWithRazorpay() {
            loadScript('https://checkout.razorpay.com/v1/checkout.js').then(function () {
                var rzp = new window.Razorpay({
                    key: checkout.key_id,
                    amount: checkout.amount,
                    currency: checkout.currency,
                    order_id: checkout.order_id,
                    name: 'Verapay',
                    description: 'Payment ' + reference,
                    handler: function () { pollUntilResolved(); },
                    modal: {
                        ondismiss: function () {
                            statusEl.textContent = 'Payment window closed. Click "Pay now" to try again.';
                            payButton.disabled = false;
                        },
                    },
                });
                rzp.on('payment.failed', function () {
                    statusEl.textContent = 'The gateway declined this payment. Click "Pay now" to try again.';
                    payButton.disabled = false;
                });
                rzp.open();
            }).catch(function () {
                statusEl.textContent = 'Could not load the payment window. Please try again.';
                payButton.disabled = false;
            });
        }

        function payWithCashfree() {
            loadScript('https://sdk.cashfree.com/js/v3/cashfree.js').then(function () {
                var cashfree = window.Cashfree({ mode: checkout.environment });
                cashfree.checkout({
                    paymentSessionId: checkout.payment_session_id,
                    redirectTarget: '_modal',
                }).then(function () {
                    pollUntilResolved();
                });
            }).catch(function () {
                statusEl.textContent = 'Could not load the payment window. Please try again.';
                payButton.disabled = false;
            });
        }

        payButton.addEventListener('click', function () {
            payButton.disabled = true;
            statusEl.textContent = '';
            if (checkout.provider === 'razorpay') {
                payWithRazorpay();
            } else if (checkout.provider === 'cashfree') {
                payWithCashfree();
            } else {
                statusEl.textContent = 'This payment cannot be completed right now.';
                payButton.disabled = false;
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
