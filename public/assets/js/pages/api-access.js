(function () {
    'use strict';
    const { apiFetch, showToast, setButtonLoading, escapeHtml, formatIST, formatISTDate } = window.Verapay;

    const loadingEl = document.getElementById('api-access-loading');
    const contentEl = document.getElementById('api-access-content');

    // Copy-to-clipboard for every [data-copy-target] button — mirrors the
    // .code-copy-btn pattern already used in api-docs.php's code blocks
    // (gateway-docs.js), just scoped to this page's input fields instead.
    document.querySelectorAll('.aa-copy-btn').forEach((btn) => {
        const defaultIcon = btn.querySelector('.aa-copy-icon-default');
        const copiedIcon = btn.querySelector('.aa-copy-icon-copied');
        let resetTimer;

        btn.addEventListener('click', async () => {
            const target = document.getElementById(btn.dataset.copyTarget);
            const text = target ? target.value : '';
            if (!text) {
                showToast('Nothing to copy yet.', 'info');
                return;
            }
            try {
                await navigator.clipboard.writeText(text);
            } catch (err) {
                showToast('Unable to copy — your browser blocked clipboard access.', 'error');
                return;
            }
            defaultIcon?.classList.add('hidden');
            copiedIcon?.classList.remove('hidden');
            btn.setAttribute('aria-label', 'Copied to clipboard');
            clearTimeout(resetTimer);
            resetTimer = setTimeout(() => {
                defaultIcon?.classList.remove('hidden');
                copiedIcon?.classList.add('hidden');
                btn.setAttribute('aria-label', 'Copy to clipboard');
            }, 1800);
        });
    });

    const ipBadgeClass = { pending: 'badge-warning', approved: 'badge-success', rejected: 'badge-danger' };
    const ipBadgeLabel = { pending: 'Pending', approved: 'Approved', rejected: 'Rejected' };

    function renderWhitelistedIps(ips) {
        const list = document.getElementById('aa-whitelisted-ips');
        if (!ips.length) {
            list.innerHTML = '<li class="text-sm text-text-secondary">No IPs requested yet — submit one below.</li>';
            return;
        }
        list.innerHTML = ips.map((row) => `
            <li class="flex items-center justify-between gap-3 rounded-sm border border-border px-3 py-2">
                <span class="flex items-center gap-2">
                    <span class="font-mono text-sm text-text-primary">${escapeHtml(row.ip_address)}</span>
                    <span class="${ipBadgeClass[row.status] || 'badge-neutral'} !text-xs">${ipBadgeLabel[row.status] || row.status}</span>
                </span>
                <span class="text-xs text-text-secondary">${formatISTDate(row.created_at)}</span>
            </li>`).join('');
    }

    async function loadApiAccess() {
        const { success, data, message } = await apiFetch('/api/settings/api-credentials.php');
        loadingEl.classList.add('hidden');
        contentEl.classList.remove('hidden');

        if (!success) {
            showToast(message || 'Unable to load API access.', 'error');
            return;
        }

        document.getElementById('aa-client-key').value = data.client_key;
        document.getElementById('aa-secret-key').value = data.secret_key_plaintext || data.secret_key_masked;
        if (data.secret_key_plaintext) {
            showToast('API credentials created — copy your secret key now, it will not be shown again.', 'success');
        }
        document.getElementById('aa-bearer-token').value = data.bearer_token || '';
        document.getElementById('aa-token-meta').textContent = data.bearer_token_generated_at
            ? `Generated ${formatIST(data.bearer_token_generated_at)}`
            : 'No token generated yet.';
        document.getElementById('aa-payout-url').value = data.payout_callback_url || '';
        document.getElementById('aa-payin-url').value = data.payin_callback_url || '';
        document.getElementById('aa-webhook-secret').value = data.webhook_signing_secret_plaintext
            || (data.webhook_signing_secret_configured ? '••••••••••••••••' : '');
        if (data.webhook_signing_secret_plaintext) {
            showToast('Webhook signing secret generated — copy it now, it will not be shown again.', 'success');
        }
        renderWhitelistedIps(data.whitelisted_ips);
    }

    document.getElementById('aa-generate-token').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        setButtonLoading(btn, true);
        const { success, data, message } = await apiFetch('/api/settings/generate-api-token.php', { method: 'POST' });
        setButtonLoading(btn, false);
        if (!success) {
            showToast(message || 'Unable to generate a token.', 'error');
            return;
        }
        document.getElementById('aa-bearer-token').value = data.bearer_token;
        document.getElementById('aa-token-meta').textContent = 'Generated just now';
        showToast(message || 'Token generated.', 'success');
    });

    document.getElementById('aa-rotate-secret').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        setButtonLoading(btn, true);
        const { success, data, message } = await apiFetch('/api/settings/rotate-api-secret.php', { method: 'POST' });
        setButtonLoading(btn, false);
        if (!success) {
            showToast(message || 'Unable to rotate the secret key.', 'error');
            return;
        }
        document.getElementById('aa-secret-key').value = data.secret_key;
        showToast(message || 'Secret key rotated.', 'success');
    });

    document.getElementById('aa-rotate-webhook-secret').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        setButtonLoading(btn, true);
        const { success, data, message } = await apiFetch('/api/settings/rotate-webhook-secret.php', { method: 'POST' });
        setButtonLoading(btn, false);
        if (!success) {
            showToast(message || 'Unable to rotate the webhook signing secret.', 'error');
            return;
        }
        document.getElementById('aa-webhook-secret').value = data.webhook_signing_secret;
        showToast(message || 'Webhook signing secret rotated.', 'success');
    });

    document.getElementById('aa-save-webhooks').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const errorEl = document.getElementById('aa-webhook-error');
        errorEl.classList.add('hidden');
        setButtonLoading(btn, true);
        const { success, message } = await apiFetch('/api/settings/save-api-webhooks.php', {
            method: 'POST',
            body: {
                payout_callback_url: document.getElementById('aa-payout-url').value.trim(),
                payin_callback_url: document.getElementById('aa-payin-url').value.trim(),
            },
        });
        setButtonLoading(btn, false);
        if (!success) {
            errorEl.textContent = message || 'Unable to save webhook configuration.';
            errorEl.classList.remove('hidden');
            return;
        }
        showToast('Webhook configuration saved.', 'success');
    });

    document.getElementById('aa-ip-request').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const input = document.getElementById('aa-ip-new');
        const errorEl = document.getElementById('aa-ip-error');
        errorEl.classList.add('hidden');

        if (!input.value.trim()) {
            errorEl.textContent = 'Enter an IP address first.';
            errorEl.classList.remove('hidden');
            return;
        }

        setButtonLoading(btn, true);
        const { success, message } = await apiFetch('/api/settings/request-api-ip.php', {
            method: 'POST',
            body: { ip_address: input.value.trim() },
        });
        setButtonLoading(btn, false);

        if (!success) {
            errorEl.textContent = message || 'Unable to submit that request.';
            errorEl.classList.remove('hidden');
            return;
        }

        input.value = '';
        showToast(message || 'Request submitted.', 'success');
        loadApiAccess();
    });

    loadApiAccess();
})();
