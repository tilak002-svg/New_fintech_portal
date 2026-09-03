<?php
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/settings.js'];

render_hero_banner($user, 'Account security', 'Manage your password and account security.');
?>

<div class="card max-w-xl">
    <div class="flex items-center gap-2.5 mb-5">
        <span class="icon-chip-md icon-chip-brand"><?= icon('shield', 'w-4 h-4') ?></span>
        <h2 class="card-title">Change password</h2>
    </div>
    <form id="password-form" novalidate>
        <div class="mb-5">
            <label for="current-password" class="field-label">Current password<span class="text-danger" aria-hidden="true"> *</span></label>
            <input type="password" id="current-password" name="current_password" class="field-input" autocomplete="current-password" required>
        </div>
        <div class="mb-5">
            <label for="new-password" class="field-label">New password<span class="text-danger" aria-hidden="true"> *</span></label>
            <input type="password" id="new-password" name="new_password" class="field-input" autocomplete="new-password" required aria-describedby="new-password-help">
            <p id="new-password-help" class="field-help">At least 10 characters.</p>
        </div>
        <div class="mb-6">
            <label for="confirm-password" class="field-label">Confirm new password<span class="text-danger" aria-hidden="true"> *</span></label>
            <input type="password" id="confirm-password" name="confirm_password" class="field-input" autocomplete="new-password" required>
        </div>
        <p id="password-error" class="field-error hidden mb-4"></p>
        <button type="submit" id="password-submit" class="btn-primary">Update password</button>
    </form>
</div>

<?php if ($user['role'] === 'customer'): ?>
<div class="card max-w-xl mt-5">
    <div class="flex items-center gap-2.5 mb-1">
        <span class="icon-chip-md icon-chip-brand"><?= icon('key', 'w-4 h-4') ?></span>
        <h2 class="card-title">API access</h2>
    </div>
    <p class="card-subtitle mb-5">Credentials for calling Verapay's API from your own systems — separate from your login above.</p>

    <div id="api-access-loading" class="text-center py-8">
        <p class="text-md text-text-secondary">Loading…</p>
    </div>

    <div id="api-access-content" class="hidden space-y-5">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="field-label">Client key</label>
                <input type="text" id="aa-client-key" class="field-input font-mono" readonly>
            </div>
            <div>
                <label class="field-label">Secret key</label>
                <div class="flex gap-2">
                    <input type="text" id="aa-secret-key" class="field-input font-mono flex-1" readonly>
                    <button type="button" id="aa-rotate-secret" class="btn-secondary shrink-0">Rotate</button>
                </div>
                <p class="field-help">Only ever shown in full right after rotating.</p>
            </div>
        </div>

        <div>
            <label class="field-label">Bearer token</label>
            <div class="flex flex-col sm:flex-row gap-3">
                <input type="text" id="aa-bearer-token" class="field-input font-mono flex-1" readonly placeholder="No token generated yet">
                <button type="button" id="aa-generate-token" class="btn-secondary shrink-0"><?= icon('key', 'w-4 h-4') ?> Generate token</button>
            </div>
            <p id="aa-token-meta" class="field-help"></p>
            <p class="field-help">
                For a system that isn't logged in, exchange <code class="font-mono text-xs">client_key</code>/<code class="font-mono text-xs">secret_key</code> for a token instead:
                <code class="font-mono text-xs block mt-1">POST /api/auth/api-token.php {"client_key": "...", "secret_key": "..."}</code>
            </p>
        </div>

        <div>
            <label class="field-label">Whitelisted IPs</label>
            <p class="field-help mb-2">Only requests from these addresses can use your token — <strong class="text-text-primary">managed by Verapay support</strong>, not self-service, so a compromised login alone can't open access from a new location. Contact support to add or change one.</p>
            <ul id="aa-whitelisted-ips" class="space-y-1.5"></ul>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label for="aa-payin-url" class="field-label">Pay-in callback URL</label>
                <input type="url" id="aa-payin-url" class="field-input" placeholder="https://…">
            </div>
            <div>
                <label for="aa-payout-url" class="field-label">Payout callback URL</label>
                <input type="url" id="aa-payout-url" class="field-input" placeholder="https://…">
            </div>
        </div>
        <p id="aa-webhook-error" class="field-error hidden"></p>
        <button type="button" id="aa-save-webhooks" class="btn-primary"><?= icon('upload', 'w-4 h-4') ?> Save webhook URLs</button>

        <div class="pt-4 border-t border-border">
            <label class="field-label">Webhook signing secret</label>
            <div class="flex gap-2">
                <input type="text" id="aa-webhook-secret" class="field-input font-mono flex-1" readonly>
                <button type="button" id="aa-rotate-webhook-secret" class="btn-secondary shrink-0">Rotate</button>
            </div>
            <p class="field-help">
                Only ever shown in full right after rotating. Verify each delivery to your callback URLs above by recomputing <code class="font-mono text-xs">hex(HMAC_SHA256(raw_request_body, this_secret))</code> and comparing it to the <code class="font-mono text-xs">X-Verapay-Signature</code> header — reject anything that doesn't match.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>
