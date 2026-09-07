<?php require_once __DIR__ . '/../includes/banner.php';
/** Customer only. The current, canonical integration reference — supersedes
 * /admin/api-integration-docs (which still documents the pre-pivot
 * /api/deposits|withdrawals endpoints for the old browser-wallet flow). */
$extraScripts = ['/assets/js/pages/gateway-docs.js', '/assets/js/pages/api-docs.js'];

function method_badge_cd(string $method): string
{
    $class = $method === 'GET' ? 'badge-info' : 'badge-success';
    return "<span class=\"{$class} font-mono\">{$method}</span>";
}

function code_block_cd(string $code): string
{
    return '<div class="code-block group">'
        . '<pre><code>' . e($code) . '</code></pre>'
        . '<button type="button" class="code-copy-btn" data-copy-text="' . e($code) . '" aria-label="Copy code to clipboard">'
        . icon('copy', 'w-4 h-4 copy-icon-default')
        . icon('check', 'w-4 h-4 copy-icon-copied hidden text-success')
        . '</button>'
        . '</div>';
}

/**
 * A ready-to-run curl example for one endpoint, built from the same data
 * driving its "Request body" / "Response" blocks above — so it can't drift
 * out of sync with them the way a hand-written example could. Compact JSON
 * here (vs. the pretty-printed schema block already shown) keeps the
 * command itself copy-pasteable as one thing.
 */
function curl_example_cd(string $method, string $baseUrl, string $path, ?string $requestJson, ?string $exampleQuery): string
{
    $url = $baseUrl . $path . ($exampleQuery ? '?' . $exampleQuery : '');
    $lines = [$method === 'GET'
        ? "curl \"{$url}\" \\"
        : "curl -X {$method} \"{$url}\" \\"];
    $lines[] = '  -H "Authorization: Bearer $VERAPAY_TOKEN"' . ($requestJson ? ' \\' : '');
    if ($requestJson) {
        $compact = json_encode(json_decode($requestJson, true), JSON_UNESCAPED_SLASHES);
        $lines[] = '  -H "Content-Type: application/json" \\';
        $lines[] = "  -d '{$compact}'";
    }
    return code_block_cd(implode("\n", $lines));
}

$endpoints = [
    [
        'slug' => 'payin-create',
        'method' => 'POST',
        'path' => '/api/v1/payins/create',
        'summary' => 'Start a PayIn — collect a payment from your own end-customer. Returns a Verapay-hosted payment_url; redirect your customer there to pay. You never see or integrate the underlying payment gateway directly.',
        'request' => <<<JSON
{
  "amount": "500.00",
  "currency": "INR",
  "merchant_order_id": "your-own-unique-order-id",
  "end_customer_name": "Priya Sharma",
  "end_customer_email": "priya@example.com",
  "end_customer_phone": "9876543210",
  "return_url": "https://yoursite.com/order/1001/thank-you"
}
JSON,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "reference": "DX-A1B2C3D4",
    "status": "pending",
    "amount": "500.00",
    "fee": "0.00",
    "net_amount": "500.00",
    "currency": "INR",
    "merchant_order_id": "your-own-unique-order-id",
    "payment_url": "https://yourplatform.com/pay?session=6f1e...",
    "expires_at": "2026-09-04 11:30:00",
    "replayed": false
  },
  "message": "Redirect your customer to payment_url to complete this payin."
}
JSON,
        'notes' => 'merchant_order_id is required and doubles as your idempotency key — retrying the same order id returns the original result (data.replayed: true) instead of creating a duplicate. end_customer_phone is required unconditionally (some gateways require it and gateway selection is not caller-controlled). payment_url expires after 30 minutes. Nothing resolves synchronously — poll payins/status or configure a payin_callback_url (Settings → API access) for a push notification instead.',
        'try' => [
            'url' => '/api/v1/try/payins-create',
            'mutating' => true,
            'fields' => [
                ['name' => 'amount', 'label' => 'Amount (₹)', 'default' => '100.00'],
                ['name' => 'merchant_order_id', 'label' => 'Merchant order ID', 'default' => 'test-order-' . substr((string) time(), -6)],
                ['name' => 'end_customer_name', 'label' => 'Customer name', 'default' => 'Test Customer'],
                ['name' => 'end_customer_email', 'label' => 'Customer email', 'default' => 'test@example.com'],
                ['name' => 'end_customer_phone', 'label' => 'Customer phone', 'default' => '9999999999'],
            ],
        ],
    ],
    [
        'slug' => 'payin-status',
        'method' => 'GET',
        'path' => '/api/v1/payins/status',
        'summary' => 'Check a PayIn\'s current status by reference.',
        'request' => null,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "reference": "DX-A1B2C3D4",
    "status": "success",
    "amount": "500.00",
    "fee": "0.00",
    "net_amount": "500.00",
    "currency": "INR",
    "merchant_order_id": "your-own-unique-order-id",
    "end_customer_name": "Priya Sharma",
    "end_customer_email": "priya@example.com",
    "end_customer_phone": "9876543210",
    "created_at": "2026-09-04 11:00:00",
    "updated_at": "2026-09-04 11:04:12"
  },
  "message": "ok"
}
JSON,
        'notes' => 'Query param: reference (required). Scoped to your own PayIns — 404 if the reference doesn\'t belong to you.',
        'example_query' => 'reference=DX-A1B2C3D4',
        'try' => [
            'url' => '/api/v1/payins/status',
            'query' => true,
            'mutating' => false,
            'fields' => [
                ['name' => 'reference', 'label' => 'Reference', 'default' => ''],
            ],
        ],
    ],
    [
        'slug' => 'payout-create',
        'method' => 'POST',
        'path' => '/api/v1/payouts/create',
        'summary' => 'Start a PayOut — send a payment to a beneficiary you specify (a customer refund, a vendor payment, etc). Debits your available settlement balance.',
        'request' => <<<JSON
{
  "amount": "1000.00",
  "currency": "INR",
  "merchant_order_id": "your-own-unique-order-id",
  "beneficiary_name": "Rahul Verma",
  "beneficiary_account_number": "1234567890123",
  "beneficiary_ifsc": "HDFC0001234",
  "beneficiary_bank_name": "HDFC Bank",
  "beneficiary_phone": "9876543211",
  "beneficiary_address": "12 MG Road, Bengaluru"
}
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
    "currency": "INR",
    "merchant_order_id": "your-own-unique-order-id",
    "replayed": false
  },
  "message": "This payout is being processed by the payment gateway."
}
JSON,
        'notes' => 'All beneficiary_* fields are required unconditionally. merchant_order_id doubles as your idempotency key, same as PayIn. Fails 422 if amount + fee exceeds your available balance. Nothing resolves synchronously — poll payouts/status or configure a payout_callback_url.',
        'try' => [
            'url' => '/api/v1/try/payouts-create',
            'mutating' => true,
            'fields' => [
                ['name' => 'amount', 'label' => 'Amount (₹)', 'default' => '50.00'],
                ['name' => 'merchant_order_id', 'label' => 'Merchant order ID', 'default' => 'test-payout-' . substr((string) time(), -6)],
                ['name' => 'beneficiary_name', 'label' => 'Beneficiary name', 'default' => 'Test Beneficiary'],
                ['name' => 'beneficiary_account_number', 'label' => 'Account number', 'default' => '1234567890123'],
                ['name' => 'beneficiary_ifsc', 'label' => 'IFSC', 'default' => 'HDFC0001234'],
                ['name' => 'beneficiary_bank_name', 'label' => 'Bank name', 'default' => 'Test Bank'],
                ['name' => 'beneficiary_phone', 'label' => 'Beneficiary phone', 'default' => '9999999999'],
                ['name' => 'beneficiary_address', 'label' => 'Beneficiary address', 'default' => '1 Test Street'],
            ],
        ],
    ],
    [
        'slug' => 'payout-status',
        'method' => 'GET',
        'path' => '/api/v1/payouts/status',
        'summary' => 'Check a PayOut\'s current status by reference.',
        'request' => null,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "reference": "WX-E5F6A7B8",
    "status": "success",
    "amount": "1000.00",
    "fee": "10.00",
    "net_amount": "990.00",
    "currency": "INR",
    "merchant_order_id": "your-own-unique-order-id",
    "beneficiary_name": "Rahul Verma",
    "beneficiary_bank_name": "HDFC Bank",
    "created_at": "2026-09-04 11:10:00",
    "updated_at": "2026-09-04 11:12:40"
  },
  "message": "ok"
}
JSON,
        'notes' => 'Query param: reference (required). Scoped to your own PayOuts.',
        'example_query' => 'reference=WX-E5F6A7B8',
        'try' => [
            'url' => '/api/v1/payouts/status',
            'query' => true,
            'mutating' => false,
            'fields' => [
                ['name' => 'reference', 'label' => 'Reference', 'default' => ''],
            ],
        ],
    ],
    [
        'slug' => 'transactions-list',
        'method' => 'GET',
        'path' => '/api/v1/transactions/list',
        'summary' => 'List your PayIn/PayOut activity, paginated.',
        'request' => null,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "transactions": [
      { "id": 501, "type": "payin", "amount": "500.00", "fee": "0.00", "net_amount": "500.00", "currency": "INR", "status": "success", "reference": "DX-A1B2C3D4", "merchant_order_id": "your-own-unique-order-id", "created_at": "2026-09-04 11:00:00" }
    ],
    "pagination": { "page": 1, "per_page": 20, "total": 1, "total_pages": 1 }
  },
  "message": "ok"
}
JSON,
        'notes' => 'Query params: type (payin|payout), status (pending|success|failed|cancelled|refunded), from/to (YYYY-MM-DD), page, per_page (max 100).',
        'example_query' => 'status=success&page=1',
        'try' => [
            'url' => '/api/v1/transactions/list',
            'query' => true,
            'mutating' => false,
            'fields' => [
                ['name' => 'type', 'label' => 'Type (payin / payout / blank)', 'default' => ''],
                ['name' => 'status', 'label' => 'Status (blank = all)', 'default' => ''],
            ],
        ],
    ],
    [
        'slug' => 'balance',
        'method' => 'GET',
        'path' => '/api/v1/balance',
        'summary' => 'Your settlement ledger balance — funds Verapay has collected on your behalf via PayIns, minus PayOuts already disbursed. Not a "top up your own account" balance.',
        'request' => null,
        'response' => <<<JSON
{
  "success": true,
  "data": {
    "available_balance": "4500.00",
    "pending_balance": "500.00",
    "currency": "INR",
    "updated_at": "2026-09-04 11:04:12"
  },
  "message": "ok"
}
JSON,
        'notes' => null,
        'example_query' => null,
        'try' => [
            'url' => '/api/v1/balance',
            'query' => true,
            'mutating' => false,
            'fields' => [],
        ],
    ],
];

render_hero_banner(
    $user,
    'API documentation',
    'Integrate PayIns and PayOuts into your own website — collect from your customers and pay out to them, without ever touching a payment gateway directly.'
);
$baseUrl = platform_api_base_url();
$errorCodes = [
    [401, 'Invalid or expired API token.', 'Your bearer token is missing, malformed, expired, or was superseded by a later "Generate token" / "Rotate" call. Exchange your client_key/secret_key again via POST /api/auth/api-token.'],
    [401, 'This API token has been revoked. Generate a new one.', 'The token was valid but no longer matches what\'s on file for your account — someone (or you) regenerated it since it was issued.'],
    [403, 'This request\'s IP address is not whitelisted for this account. Contact support to have it added.', 'Your bearer token is valid, but the request came from an IP not on your admin-managed whitelist.'],
    [403, 'This account has been suspended.', 'Your Verapay account itself is suspended — contact support.'],
    [404, 'No payin found with that reference. / No payout found with that reference.', 'The reference doesn\'t exist, or belongs to a different merchant account than the one your token authenticates as.'],
    [405, 'Method not allowed.', 'You called an endpoint with the wrong HTTP verb — e.g. GET on /payins/create (which is POST-only).'],
    [422, 'Field-specific validation message (e.g. "amount is required", "beneficiary_ifsc is required").', 'Required-field or format validation failed. The message names the exact field.'],
    [422, 'Insufficient available balance for this payout plus the ₹X.XX fee.', 'Your settlement balance (GET /balance) doesn\'t cover amount + fee for this payout.'],
    [502, 'This payin/payout could not be started — the payment gateway rejected the request. Please try again.', 'The selected gateway synchronously rejected the request. Safe to retry — no reservation or transaction was left in a pending state.'],
    [503, 'PayIns/PayOuts are temporarily unavailable. Please try again shortly.', 'No active gateway currently has capacity (all at their configured limits, or none configured). Retry with backoff — this is not a request-side error.'],
];
?>
<div class="card mb-5">
    <p class="text-sm font-semibold text-text-primary mb-1.5">Base URL</p>
    <?= code_block_cd($baseUrl) ?>
    <p class="text-sm text-text-secondary mt-3">Every path below is relative to this — e.g. <code class="font-mono text-sm">POST <?= e($baseUrl) ?>/payins/create</code>.</p>
</div>

<div class="mb-6 flex flex-wrap justify-end gap-3">
    <a href="/api/settings/postman-collection.php" download class="btn-secondary shrink-0"><?= icon('download', 'w-4 h-4') ?>Download Postman collection</a>
    <a href="/settings" class="btn-secondary shrink-0"><?= icon('key', 'w-4 h-4') ?>Your API credentials</a>
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
    <a href="#error-codes" class="doc-jump-link">Error codes</a>
</nav>

<div class="card mb-5" id="setup">
    <h2 class="card-title mb-3">Setup — what to configure before any of this works</h2>
    <ol class="space-y-2 text-md text-text-secondary list-decimal pl-5">
        <li>Get your <code class="font-mono text-sm">client_key</code>/<code class="font-mono text-sm">secret_key</code> from <strong class="text-text-primary">Settings → API access</strong> (auto-provisioned on first visit) and exchange them for a bearer token via <code class="font-mono text-sm">POST /api/auth/api-token</code>.</li>
        <li><strong class="text-text-primary">An admin</strong> must add your server's IP address to your whitelist before any bearer-authenticated request succeeds — contact support if requests fail with a 403 mentioning your IP.</li>
        <li>Set <code class="font-mono text-sm">payin_callback_url</code> / <code class="font-mono text-sm">payout_callback_url</code> in Settings if you want outbound webhooks (see below) — optional, independent of each other.</li>
        <li>For PayIns: when a payin is created, redirect your customer's browser to the returned <code class="font-mono text-sm">payment_url</code> — a Verapay-hosted page. Don't try to integrate the underlying gateway yourself; the checkout details in that URL are intentionally opaque to you.</li>
    </ol>
</div>

<div class="card mb-5 border-l-4 border-l-danger">
    <h2 class="card-title mb-3"><?= icon('shield', 'w-5 h-5 inline -mt-1 mr-1.5') ?>Integrate from your backend only</h2>
    <p class="text-md text-text-secondary mb-3">Your <code class="font-mono text-sm">client_secret</code> authenticates as you — treat it like a password.</p>
    <ul class="space-y-2 text-md text-text-secondary list-disc pl-5">
        <li><strong class="text-text-primary">Never</strong> put <code class="font-mono text-sm">client_secret</code> (or a bearer token obtained with it) in frontend JavaScript, a mobile app, or any code that ships to a browser/device you don't control.</li>
        <li><strong class="text-text-primary">Never</strong> commit it to Git, even a private repo — use environment variables/a secrets manager on your server.</li>
        <li>The correct shape is: <strong class="text-text-primary">your frontend</strong> talks only to <strong class="text-text-primary">your backend</strong>; <strong class="text-text-primary">your backend</strong> is the only thing that ever calls Verapay. Your backend creates the PayIn and hands your frontend just the <code class="font-mono text-sm">payment_url</code> to redirect/open — nothing else from the response needs to reach the browser.</li>
        <li>Don't trust a browser redirect back to your site as proof of payment — a user can close the tab, lose connectivity, or the redirect can be tampered with. Treat only a verified <code class="font-mono text-sm">payin_callback_url</code> delivery (signature checked) or a server-side <code class="font-mono text-sm">payins/status</code> poll as authoritative before marking an order paid.</li>
    </ul>
</div>

<div class="card mb-5">
    <h2 class="card-title mb-3">Conventions</h2>
    <ul class="space-y-2 text-md text-text-secondary list-disc pl-5">
        <li><strong class="text-text-primary">Authentication:</strong> every endpoint accepts <code class="font-mono text-sm">Authorization: Bearer &lt;token&gt;</code>. The curl examples below use <code class="font-mono text-sm">$VERAPAY_TOKEN</code> as a stand-in — export your own bearer token to that shell variable to run them as-is.</li>
        <li><strong class="text-text-primary">Response envelope:</strong> <code class="font-mono text-sm">{ "success": bool, "data": ..., "message": "..." }</code> — check <code class="font-mono text-sm">success</code>, not the HTTP status alone.</li>
        <li><strong class="text-text-primary">Idempotency:</strong> <code class="font-mono text-sm">merchant_order_id</code> is your idempotency key on both create endpoints — reusing one replays the original result (<code class="font-mono text-sm">data.replayed: true</code>) instead of creating a duplicate.</li>
        <li><strong class="text-text-primary">Nothing resolves synchronously.</strong> A successful create call means "accepted," not "settled."</li>
    </ul>
</div>

<div class="space-y-4">
    <?php foreach ($endpoints as $i => $ep): ?>
        <details class="doc-endpoint" id="ep-<?= e($ep['slug']) ?>" data-try="<?= e(json_encode($ep['try'])) ?>" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <?= method_badge_cd($ep['method']) ?>
                <code class="font-mono text-md text-text-primary font-semibold flex-1"><?= e($ep['path']) ?></code>
                <?= icon('chevron-down', 'w-4 h-4 text-text-secondary shrink-0 doc-chevron') ?>
            </summary>
            <div class="doc-endpoint-body">
                <p class="text-md text-text-secondary mb-4"><?= e($ep['summary']) ?></p>

                <p class="text-sm font-semibold text-text-primary mb-1.5">Example request</p>
                <?= curl_example_cd($ep['method'], $baseUrl, $ep['path'], $ep['request'], $ep['example_query'] ?? null) ?>
                <div class="mt-4"></div>

                <?php if ($ep['request']): ?>
                    <p class="text-sm font-semibold text-text-primary mb-1.5">Request body</p>
                    <?= code_block_cd($ep['request']) ?>
                    <div class="mt-3"></div>
                <?php endif; ?>

                <p class="text-sm font-semibold text-text-primary mb-1.5">Response</p>
                <?= code_block_cd($ep['response']) ?>

                <?php if ($ep['notes']): ?>
                    <p class="text-sm text-text-secondary mt-3"><?= icon('alert-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?><?= e($ep['notes']) ?></p>
                <?php endif; ?>

                <div class="mt-5 pt-5 border-t border-border try-it-panel">
                    <div class="flex items-center gap-2 mb-3">
                        <h3 class="text-sm font-semibold text-text-primary">Try it</h3>
                        <?php if (!empty($ep['try']['mutating'])): ?>
                            <span class="badge-neutral">Sandbox only</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($ep['try']['mutating'])): ?>
                        <p class="text-sm text-text-secondary mb-3"><?= icon('alert-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?>This creates a real <?= str_contains($ep['slug'], 'payin') ? 'PayIn' : 'PayOut' ?> and updates your real balance — no money moves at the payment provider, since this is forced to a sandbox-mode gateway.</p>
                    <?php endif; ?>
                    <div class="try-it-fields grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3"></div>
                    <button type="button" class="btn-secondary try-it-send">Send request</button>
                    <div class="try-it-result mt-4 hidden">
                        <div class="flex items-center gap-3 mb-2 text-sm">
                            <span class="try-it-status font-mono"></span>
                            <span class="try-it-elapsed text-text-secondary"></span>
                        </div>
                        <div class="try-it-response"></div>
                    </div>
                </div>
            </div>
        </details>
    <?php endforeach; ?>
</div>

<div class="card mt-5" id="webhooks">
    <h2 class="card-title mb-3">Outbound webhooks</h2>
    <p class="text-md text-text-secondary mb-4">When a PayIn or PayOut you created reaches a final state, Verapay <code class="font-mono text-sm">POST</code>s a notification to whichever of <code class="font-mono text-sm">payin_callback_url</code> / <code class="font-mono text-sm">payout_callback_url</code> matches its direction (Settings → API access), queued and retried on failure with exponential backoff (5 attempts total) — still poll the status endpoints above as your source of truth, but a transient outage on your end won't lose the notification.</p>

    <p class="text-sm text-text-secondary mb-4 rounded-md border border-border bg-surface-muted px-4 py-3"><?= icon('alert-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?><strong class="text-text-primary">Testing locally against a receiver on your own machine, with Verapay running in Docker:</strong> <code class="font-mono text-sm">localhost</code> inside the Verapay container means the container itself, not your machine — a callback URL like <code class="font-mono text-sm">http://localhost:9099/…</code> will fail to connect. Use <code class="font-mono text-sm">http://host.docker.internal:PORT/…</code> instead, which Docker Desktop routes back to your host machine. (<code class="font-mono text-sm">http://</code> callback URLs are only accepted outside production, for <code class="font-mono text-sm">localhost</code>/<code class="font-mono text-sm">127.0.0.1</code>/<code class="font-mono text-sm">host.docker.internal</code> — a real destination must be <code class="font-mono text-sm">https://</code>.) For testing against a real Cashfree/Razorpay sandbox webhook specifically, the gateway itself needs a publicly reachable HTTPS URL — a local tunnel (e.g. ngrok) or a staging deployment, since the provider cannot reach your laptop directly.</p>

    <p class="text-sm font-semibold text-text-primary mb-1.5">PayIn payload</p>
    <?= code_block_cd(<<<JSON
{
  "event": "payin.success",
  "reference": "DX-A1B2C3D4",
  "type": "payin",
  "status": "success",
  "amount": "500.00",
  "fee": "0.00",
  "net_amount": "500.00",
  "currency": "INR",
  "merchant_order_id": "your-own-unique-order-id",
  "end_customer": { "name": "Priya Sharma", "email": "priya@example.com", "phone": "9876543210" },
  "occurred_at": "2026-09-04T11:04:12+00:00"
}
JSON) ?>

    <p class="text-sm font-semibold text-text-primary mt-4 mb-1.5">PayOut payload</p>
    <?= code_block_cd(<<<JSON
{
  "event": "payout.success",
  "reference": "WX-E5F6A7B8",
  "type": "payout",
  "status": "success",
  "amount": "1000.00",
  "fee": "10.00",
  "net_amount": "990.00",
  "currency": "INR",
  "merchant_order_id": "your-own-unique-order-id",
  "beneficiary": { "name": "Rahul Verma", "bank_name": "HDFC Bank", "account_last4": "0123", "ifsc": "HDFC0001234" },
  "occurred_at": "2026-09-04T11:12:40+00:00"
}
JSON) ?>

    <p class="text-sm font-semibold text-text-primary mt-4 mb-1.5">Verifying the signature</p>
    <p class="text-md text-text-secondary mb-3">Every delivery carries <code class="font-mono text-sm">X-Verapay-Signature</code>: <code class="font-mono text-sm">hex(HMAC_SHA256(raw_request_body, your_webhook_signing_secret))</code>. Recompute over the exact raw bytes received and reject anything that doesn't match with a constant-time comparison. The signing secret is shown once in Settings → API access.</p>
    <?= code_block_cd(<<<PHP
// PHP receiver example
\$rawBody = file_get_contents('php://input');
\$expected = hash_hmac('sha256', \$rawBody, \$yourStoredWebhookSigningSecret);
if (!hash_equals(\$expected, \$_SERVER['HTTP_X_VERAPAY_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
PHP) ?>

    <p class="text-sm text-text-secondary mt-4"><?= icon('alert-circle', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?>Retried up to 5 times with exponential backoff (1, 5, 30, 120, 360 minutes) on a non-2xx response or connection failure. Still poll as a fallback, not as your only source of truth — treat the callback as a low-latency notification, not the sole confirmation channel.</p>
</div>

<div class="card mt-5" id="error-codes">
    <h2 class="card-title mb-1">Error codes</h2>
    <p class="text-md text-text-secondary mb-4">Always check <code class="font-mono text-sm">success</code> in the response body, not just the HTTP status. Every error shares the same envelope: <code class="font-mono text-sm">{ "success": false, "data": ..., "message": "..." }</code>.</p>
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">HTTP status</th>
                    <th scope="col">Message</th>
                    <th scope="col">Meaning</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($errorCodes as [$code, $msg, $meaning]): ?>
                    <tr>
                        <td class="font-mono text-sm"><?= e((string) $code) ?></td>
                        <td class="text-sm text-text-primary"><?= e($msg) ?></td>
                        <td class="text-sm text-text-secondary"><?= e($meaning) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
