<?php require_once __DIR__ . '/../../includes/banner.php';
/** Admin only. Static reference page for the customer-facing integration surface — auth exchange, payin/payout, and outbound webhooks. Hand this to a customer's developer; nothing on it is admin-specific. */
$extraScripts = ['/assets/js/pages/gateway-docs.js'];

function method_badge_ai(string $method): string
{
    $class = $method === 'GET' ? 'badge-info' : 'badge-success';
    return "<span class=\"{$class} font-mono\">{$method}</span>";
}

function code_block_ai(string $code): string
{
    return '<div class="code-block group">'
        . '<pre><code>' . e($code) . '</code></pre>'
        . '<button type="button" class="code-copy-btn" data-copy-text="' . e($code) . '" aria-label="Copy code to clipboard">'
        . icon('copy', 'w-4 h-4 copy-icon-default')
        . icon('check', 'w-4 h-4 copy-icon-copied hidden text-success')
        . '</button>'
        . '</div>';
}

$endpoints = [
    [
        'slug' => 'token',
        'method' => 'POST',
        'path' => '/api/auth/api-token.php',
        'summary' => 'Exchange your client_key/secret_key for a bearer token. Not session/CSRF-guarded — this is the entry point for a server that has no browser session.',
        'request' => <<<JSON
{ "client_key": "VP1A2B3C4D5E", "secret_key": "the_plaintext_secret_shown_once_in_Settings" }
JSON,
        'response' => <<<JSON
{
  "success": true,
  "data": { "bearer_token": "eyJhbGciOi..." },
  "message": "ok"
}
JSON,
        'notes' => "Fails 403 if the request's source IP is not on this customer's whitelist (admin-managed — see below) even with a correct secret_key. The returned token is identical to the one shown in Settings → API access, since generating it here overwrites the stored token the same way clicking \"Generate token\" does — the previous token stops working immediately either way.",
    ],
    [
        'slug' => 'payin',
        'method' => 'POST',
        'path' => '/api/deposits/create.php',
        'summary' => 'Start a pay-in (deposit). Same endpoint the dashboard itself calls — send a bearer token instead of a session cookie and it behaves as the partner API.',
        'request' => <<<JSON
{ "amount": "500.00", "method": "Bank transfer", "idempotency_key": "your-own-unique-id" }
JSON,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "reference": "DX-A1B2C3D4",
    "status": "pending",
    "amount": "500.00",
    "fee": "5.00",
    "net_amount": "495.00",
    "method": "Bank transfer",
    "checkout": {
      "provider": "razorpay",
      "order_id": "order_...",
      "key_id": "rzp_live_...",
      "amount": 50000,
      "currency": "INR"
    },
    "replayed": false
  },
  "message": "Complete your payment to finish this deposit."
}
JSON,
        'notes' => 'Authorization: Bearer <token> header required. method is "Bank transfer" or "Debit card". idempotency_key is optional but recommended — a retried request with the same key replays the original result instead of creating a second deposit. checkout is present only when the selected gateway has a live integration (currently Razorpay or Cashfree) — its shape is provider-specific; render whichever provider\'s own checkout widget using these fields. If null, the deposit is pending manual/legacy settlement. This call never returns "success" synchronously for a live-integrated gateway — completion always arrives via the webhook described below (or by polling transactions/list.php).',
    ],
    [
        'slug' => 'payout',
        'method' => 'POST',
        'path' => '/api/withdrawals/create.php',
        'summary' => 'Start a payout (withdrawal) to the account holder\'s bank account on file.',
        'request' => <<<JSON
{ "amount": "1000.00", "destination": "HDFC Bank ****1234", "idempotency_key": "your-own-unique-id" }
JSON,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "reference": "WX-E5F6A7B8",
    "status": "pending",
    "amount": "1000.00",
    "fee": "10.00",
    "net_amount": "990.00",
    "replayed": false
  },
  "message": "Your withdrawal is being processed by the payment gateway."
}
JSON,
        'notes' => 'Authorization: Bearer <token> header required. destination is a free-text label only — the actual bank account transferred to is whatever is on file in Settlement banking (Settings/KYC), not this field; add or update that first, or this call fails 422. Like deposits, this never resolves synchronously — watch for the outbound webhook, or poll transactions/list.php.',
    ],
    [
        'slug' => 'poll',
        'method' => 'GET',
        'path' => '/api/transactions/list.php',
        'summary' => 'Poll transaction status directly, as a fallback or alternative to the outbound webhook below.',
        'request' => null,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "transactions": [
      { "id": 501, "type": "deposit", "method": "Bank transfer", "amount": "500.00", "fee": "5.00", "net_amount": "495.00", "currency": "INR", "status": "success", "reference": "DX-A1B2C3D4", "created_at": "2026-09-03 10:15:00" }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
  },
  "message": "ok"
}
JSON,
        'notes' => 'Authorization: Bearer <token> header required — automatically scoped to your own transactions only. Query params: type (deposit|withdrawal), status (pending|success|failed|cancelled|refunded), from/to (YYYY-MM-DD), search (matches reference), sort (newest|oldest|amount_desc|amount_asc), page, per_page (max 100).',
    ],
];

render_hero_banner(
    $user,
    'Customer API integration',
    'Reference for the surface a customer\'s own systems integrate against — authentication, pay-in, payout, and outbound webhooks. Not the same as gateway management (admin-only) — this is what to hand a customer\'s developer.'
); ?>
<div class="mb-6 flex justify-end">
    <a href="/admin/gateways/docs" class="btn-secondary shrink-0"><?= icon('documentation', 'w-4 h-4') ?>Gateway management docs</a>
</div>

<nav class="flex flex-wrap items-center gap-2 mb-6" aria-label="Jump to section">
    <a href="#setup" class="doc-jump-link">Setup</a>
    <?php foreach ($endpoints as $ep): ?>
        <a href="#ep-<?= e($ep['slug']) ?>" class="doc-jump-link">
            <span class="font-mono text-xs font-bold <?= $ep['method'] === 'GET' ? 'text-info' : 'text-success' ?>"><?= e($ep['method']) ?></span>
            <?= e($ep['slug']) ?>
        </a>
    <?php endforeach; ?>
    <a href="#webhooks" class="doc-jump-link">Outbound webhooks</a>
</nav>

<div class="card mb-5" id="setup">
    <h2 class="card-title mb-3">Setup — what to configure before any of this works</h2>
    <ol class="space-y-2 text-md text-text-secondary list-decimal pl-5">
        <li>The customer generates a <code class="font-mono text-sm">client_key</code>/<code class="font-mono text-sm">secret_key</code> pair themselves, in <strong class="text-text-primary">Settings → API access</strong> (auto-provisioned on first visit; the secret is shown once, on generation/rotation only).</li>
        <li><strong class="text-text-primary">An admin</strong> must add the IP address(es) their server calls from to that customer's whitelist — <strong class="text-text-primary">Customers → (select customer) → API access → Add IP</strong>. Every bearer-authenticated request fails closed with <code class="font-mono text-sm">403</code> until this is done — deliberately not customer self-service (see <code class="font-mono text-sm">customer_whitelisted_ips</code> in <code class="font-mono text-sm">database/schema.sql</code> for why).</li>
        <li>The customer sets <code class="font-mono text-sm">payin_callback_url</code> / <code class="font-mono text-sm">payout_callback_url</code> in the same Settings screen if they want outbound webhooks (see below) — both optional, independent of each other.</li>
        <li>To accept real money, an admin must have added and activated a payment gateway with provider <code class="font-mono text-sm">razorpay</code> or <code class="font-mono text-sm">cashfree</code> and its live credentials (<a href="/admin/gateways" class="text-brand-emphasis underline">Payment gateways</a>) — otherwise pay-ins/payouts still get accepted but settle the old synchronous/manual way instead of through a real provider.</li>
    </ol>
</div>

<div class="card mb-5">
    <h2 class="card-title mb-3">Conventions</h2>
    <ul class="space-y-2 text-md text-text-secondary list-disc pl-5">
        <li><strong class="text-text-primary">Authentication:</strong> every endpoint below accepts <code class="font-mono text-sm">Authorization: Bearer &lt;token&gt;</code> as an alternative to a browser session — no CSRF token needed on that path (a stolen bearer token isn't a CSRF vector the way a cookie is).</li>
        <li><strong class="text-text-primary">Response envelope:</strong> every response is <code class="font-mono text-sm">{ "success": bool, "data": ..., "message": "..." }</code>. Check <code class="font-mono text-sm">success</code>, not the HTTP status alone.</li>
        <li><strong class="text-text-primary">Idempotency:</strong> both create endpoints accept an optional <code class="font-mono text-sm">idempotency_key</code> — reusing one replays the original result (HTTP 200, <code class="font-mono text-sm">data.replayed: true</code>) instead of creating a duplicate transaction. Use one whenever a retry is possible (timeout, connection drop).</li>
        <li><strong class="text-text-primary">Nothing here resolves synchronously.</strong> A successful create call means "accepted," not "settled" — the transaction is <code class="font-mono text-sm">pending</code> until the outbound webhook fires or you poll and see <code class="font-mono text-sm">success</code>/<code class="font-mono text-sm">failed</code>.</li>
    </ul>
</div>

<div class="space-y-4">
    <?php foreach ($endpoints as $i => $ep): ?>
        <details class="doc-endpoint" id="ep-<?= e($ep['slug']) ?>" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <?= method_badge_ai($ep['method']) ?>
                <code class="font-mono text-md text-text-primary font-semibold flex-1"><?= e($ep['path']) ?></code>
                <?= icon('chevron-down', 'w-4 h-4 text-text-secondary shrink-0 doc-chevron') ?>
            </summary>
            <div class="doc-endpoint-body">
                <p class="text-md text-text-secondary mb-4"><?= e($ep['summary']) ?></p>

                <?php if ($ep['request']): ?>
                    <p class="text-sm font-semibold text-text-primary mb-1.5">Request body</p>
                    <?= code_block_ai($ep['request']) ?>
                    <div class="mt-3"></div>
                <?php endif; ?>

                <p class="text-sm font-semibold text-text-primary mb-1.5">Response</p>
                <?= code_block_ai($ep['response']) ?>

                <p class="text-sm text-text-secondary mt-3"><?= icon('alert-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?><?= e($ep['notes']) ?></p>
            </div>
        </details>
    <?php endforeach; ?>
</div>

<div class="card mt-5" id="webhooks">
    <h2 class="card-title mb-3">Outbound webhooks</h2>
    <p class="text-md text-text-secondary mb-4">When a pay-in or payout you created reaches a final state (<code class="font-mono text-sm">success</code> or <code class="font-mono text-sm">failed</code>), Verapay <code class="font-mono text-sm">POST</code>s a notification to whichever of <code class="font-mono text-sm">payin_callback_url</code> / <code class="font-mono text-sm">payout_callback_url</code> matches the transaction's direction (Settings → API access). Delivery is best-effort, single-attempt, short-timeout — treat it as a nice-to-have push notification, not a reliable log; poll <code class="font-mono text-sm">/api/transactions/list.php</code> if a delivery might have been missed.</p>

    <p class="text-sm font-semibold text-text-primary mb-1.5">Payload</p>
    <?= code_block_ai(<<<JSON
{
  "event": "deposit.success",
  "reference": "DX-A1B2C3D4",
  "type": "deposit",
  "status": "success",
  "amount": "500.00",
  "fee": "5.00",
  "net_amount": "495.00",
  "currency": "INR",
  "occurred_at": "2026-09-03T10:15:00+00:00"
}
JSON) ?>

    <p class="text-sm font-semibold text-text-primary mt-4 mb-1.5">Verifying the signature</p>
    <p class="text-md text-text-secondary mb-3">Every delivery carries an <code class="font-mono text-sm">X-Verapay-Signature</code> header: <code class="font-mono text-sm">hex(HMAC_SHA256(raw_request_body, your_webhook_signing_secret))</code>. Recompute it over the exact raw bytes received (not a re-serialized copy) and reject anything that doesn't match with <code class="font-mono text-sm">hash_equals()</code> or your language's constant-time comparison. The signing secret is shown once in Settings → API access, under "Webhook signing secret" — separate from your API <code class="font-mono text-sm">secret_key</code>, and rotatable independently.</p>
    <?= code_block_ai(<<<PHP
// PHP receiver example
\$rawBody = file_get_contents('php://input');
\$expected = hash_hmac('sha256', \$rawBody, \$yourStoredWebhookSigningSecret);
if (!hash_equals(\$expected, \$_SERVER['HTTP_X_VERAPAY_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
PHP) ?>

    <p class="text-sm text-text-secondary mt-4"><?= icon('alert-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?>No retry on delivery failure (non-2xx response, timeout, DNS failure, etc.) — this app has no background job queue. Design your integration to tolerate a missed delivery by polling as a fallback, not as the only source of truth.</p>
</div>
