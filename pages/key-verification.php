<?php
/** Customer-only. Runs real checks against the merchant API — the bearer
 * token already issued to this customer (Settings → API access) is used
 * to make an actual authenticated call to /api/v1/balance from the
 * browser, exercising the exact same auth + IP-whitelist path a real
 * external integration would hit. Nothing here is simulated. */
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/key-verification.js'];

render_hero_banner(
    $user,
    'API & Key Verification',
    'Verify that your API credentials and server configuration are correctly connected to Verapay.'
);
?>

<div class="card mb-5 text-center py-10" id="kv-status-card">
    <span class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-surface-muted text-text-secondary mb-4" id="kv-status-icon">
        <?= icon('shield', 'w-7 h-7') ?>
    </span>
    <h2 class="text-2xl font-semibold text-text-primary mb-1" id="kv-status-title">Not verified yet</h2>
    <p class="text-md text-text-secondary mb-1" id="kv-status-subtitle">Run a check to confirm your API connection is working.</p>
    <p class="text-sm text-text-secondary" id="kv-status-meta"></p>
</div>

<div class="card mb-5">
    <h2 class="card-title mb-4">Verification checks</h2>
    <ul class="space-y-3" id="kv-checklist">
        <li class="kv-check flex items-center gap-3" data-check="client_key">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">Client key provisioned</span>
        </li>
        <li class="kv-check flex items-center gap-3" data-check="secret_key">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">API credentials provisioned</span>
        </li>
        <li class="kv-check flex items-center gap-3" data-check="bearer_token">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">Bearer token generated</span>
        </li>
        <li class="kv-check flex items-center gap-3" data-check="reachable">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">API base URL reachable</span>
        </li>
        <li class="kv-check flex items-center gap-3" data-check="auth">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">Authentication successful</span>
        </li>
        <li class="kv-check flex items-center gap-3" data-check="ip">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">Server IP address whitelisted</span>
        </li>
        <li class="kv-check flex items-center gap-3" data-check="request">
            <span class="kv-check-icon shrink-0"><?= icon('circle', 'w-5 h-5 text-text-secondary') ?></span>
            <span class="text-md text-text-primary">API request successful</span>
        </li>
    </ul>

    <div id="kv-failure-detail" class="hidden mt-5 pt-5 border-t border-border">
        <div class="rounded-md border border-danger/30 bg-danger-bg px-4 py-3 text-sm text-text-primary" id="kv-failure-message"></div>
        <button type="button" class="btn-secondary mt-3 hidden" id="kv-request-ip-btn"></button>
    </div>

    <div class="flex flex-col sm:flex-row items-center gap-3 mt-6">
        <button type="button" class="btn-primary" id="kv-verify-btn"><?= icon('shield', 'w-4 h-4') ?> Verify API connection</button>
        <a href="/api-access" class="btn-secondary" id="kv-generate-token-link">API access settings</a>
        <a href="/support" class="btn-secondary hidden" id="kv-contact-support">Contact support</a>
    </div>
</div>
