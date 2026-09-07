<?php
/** Customer-only. Credentials for calling Verapay's API — the Base URL,
 * client key/secret, bearer token, webhook signing secret, admin-managed
 * IP whitelist, and callback URLs, all in one place. See
 * public/api/settings/api-credentials.php for the data this renders. */
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/api-access.js'];
$baseUrl = platform_api_base_url();

function aa_copy_btn(string $targetId): string
{
    return '<button type="button" class="btn-icon aa-copy-btn" data-copy-target="' . e($targetId) . '" aria-label="Copy to clipboard">'
        . icon('copy', 'w-4 h-4 aa-copy-icon-default')
        . icon('check-circle', 'w-4 h-4 aa-copy-icon-copied hidden text-success')
        . '</button>';
}

render_hero_banner($user, 'API Access', 'Connect your website or application to Verapay securely.');
?>

<div class="card mb-5 border-l-4 border-l-brand">
    <p class="text-sm font-semibold text-brand uppercase tracking-wide mb-1.5">API Base URL</p>
    <div class="flex items-center gap-2">
        <input type="text" class="field-input font-mono flex-1 text-lg" id="aa-base-url" readonly value="<?= e($baseUrl) ?>">
        <?= aa_copy_btn('aa-base-url') ?>
    </div>
    <p class="text-sm text-text-secondary mt-3">Use this Base URL for every Verapay API request — see the full <a href="/api-docs" class="text-brand-emphasis underline">API documentation</a>.</p>
</div>

<div class="rounded-md border border-warning/30 bg-warning-bg px-4 py-3 mb-5 flex items-start gap-2.5">
    <?= icon('shield', 'w-5 h-5 text-warning shrink-0 mt-0.5') ?>
    <p class="text-sm text-text-primary"><strong>Keep your API credentials secure.</strong> Never expose your client secret, bearer token, or webhook signing secret in frontend JavaScript, mobile applications, or public repositories. Only your own server should hold them.</p>
</div>

<div id="api-access-loading" class="text-center py-12">
    <p class="text-md text-text-secondary">Loading…</p>
</div>

<div id="api-access-content" class="hidden space-y-5">
    <div class="card">
        <div class="flex items-center gap-2.5 mb-5">
            <span class="icon-chip-md icon-chip-brand"><?= icon('key', 'w-4 h-4') ?></span>
            <h2 class="card-title">API credentials</h2>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
            <div>
                <label class="field-label">Client key</label>
                <div class="flex gap-2">
                    <input type="text" id="aa-client-key" class="field-input font-mono flex-1" readonly>
                    <?= aa_copy_btn('aa-client-key') ?>
                </div>
            </div>
            <div>
                <label class="field-label">Client secret</label>
                <div class="flex flex-col sm:flex-row gap-3">
                    <input type="text" id="aa-secret-key" class="field-input font-mono flex-1" readonly>
                    <?= aa_copy_btn('aa-secret-key') ?>
                    <button type="button" id="aa-rotate-secret" class="btn-secondary shrink-0">Rotate</button>
                </div>
                <p class="field-help">Only ever shown in full right after rotating.</p>
            </div>
        </div>

        <div class="mb-5">
            <label class="field-label">Bearer token</label>
            <div class="flex flex-col sm:flex-row gap-3">
                <input type="text" id="aa-bearer-token" class="field-input font-mono flex-1" readonly placeholder="No token generated yet">
                <?= aa_copy_btn('aa-bearer-token') ?>
                <button type="button" id="aa-generate-token" class="btn-secondary shrink-0"><?= icon('key', 'w-4 h-4') ?> Generate token</button>
            </div>
            <p id="aa-token-meta" class="field-help"></p>
            <p class="field-help">
                For a system that isn't logged in, exchange <code class="font-mono text-xs">client_key</code>/<code class="font-mono text-xs">secret_key</code> for a token instead:
                <code class="font-mono text-xs block mt-1">POST /api/auth/api-token.php {"client_key": "...", "secret_key": "..."}</code>
            </p>
        </div>

        <div class="pt-5 border-t border-border">
            <label class="field-label">Webhook signing secret</label>
            <div class="flex flex-col sm:flex-row gap-3">
                <input type="text" id="aa-webhook-secret" class="field-input font-mono flex-1" readonly>
                <?= aa_copy_btn('aa-webhook-secret') ?>
                <button type="button" id="aa-rotate-webhook-secret" class="btn-secondary shrink-0">Rotate</button>
            </div>
            <p class="field-help">
                Only ever shown in full right after rotating. Verify each delivery to your callback URLs below by recomputing <code class="font-mono text-xs">hex(HMAC_SHA256(raw_request_body, this_secret))</code> and comparing it to the <code class="font-mono text-xs">X-Verapay-Signature</code> header.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="flex items-center gap-2.5 mb-1">
            <span class="icon-chip-md icon-chip-brand"><?= icon('shield', 'w-4 h-4') ?></span>
            <h2 class="card-title">Whitelisted IPs</h2>
        </div>
        <p class="card-subtitle mb-4">Only requests from these addresses can use your token — <strong class="text-text-primary">managed by Verapay support</strong>, not self-service, so a compromised login alone can't open API access from a new location. Contact support to add or change one.</p>
        <ul id="aa-whitelisted-ips" class="space-y-1.5"></ul>
    </div>

    <div class="card">
        <div class="flex items-center gap-2.5 mb-5">
            <span class="icon-chip-md icon-chip-brand"><?= icon('notification', 'w-4 h-4') ?></span>
            <h2 class="card-title">Callback URLs</h2>
        </div>
        <p class="text-sm text-text-secondary mb-4">Not generated by Verapay — enter a URL that already exists on <strong class="text-text-primary">your own server</strong>. Verapay sends a <code class="font-mono text-xs">POST</code> request there whenever a PayIn or PayOut reaches a final state; see <a href="/api-docs#webhooks" class="text-brand hover:underline">Outbound webhooks</a> in the API docs for the payload shape and how to verify it.</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="aa-payin-url" class="field-label">Pay-in callback URL</label>
                <input type="url" id="aa-payin-url" class="field-input" placeholder="https://yourserver.com/webhooks/payin">
                <p class="field-help">Called after a PayIn you created succeeds, fails, or is cancelled.</p>
            </div>
            <div>
                <label for="aa-payout-url" class="field-label">Payout callback URL</label>
                <input type="url" id="aa-payout-url" class="field-input" placeholder="https://yourserver.com/webhooks/payout">
                <p class="field-help">Called after a PayOut you created succeeds or fails.</p>
            </div>
        </div>
        <p id="aa-webhook-error" class="field-error hidden mt-3"></p>
        <button type="button" id="aa-save-webhooks" class="btn-primary mt-4"><?= icon('upload', 'w-4 h-4') ?> Save webhook URLs</button>
    </div>

    <div class="card !p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <p class="text-md font-medium text-text-primary">Not sure everything's wired up correctly?</p>
            <p class="text-sm text-text-secondary">Run a live check of your credentials, IP whitelist, and connectivity.</p>
        </div>
        <a href="/key-verification" class="btn-secondary shrink-0"><?= icon('shield', 'w-4 h-4') ?> Verify API connection</a>
    </div>
</div>
