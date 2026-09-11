(function () {
    'use strict';
    const { apiFetch, escapeHtml, setButtonLoading, showToast, formatIST } = window.Verapay;

    const btn = document.getElementById('kv-verify-btn');
    const statusCard = document.getElementById('kv-status-card');
    const statusIcon = document.getElementById('kv-status-icon');
    const statusTitle = document.getElementById('kv-status-title');
    const statusSubtitle = document.getElementById('kv-status-subtitle');
    const statusMeta = document.getElementById('kv-status-meta');
    const failureDetail = document.getElementById('kv-failure-detail');
    const failureMessage = document.getElementById('kv-failure-message');
    const contactSupport = document.getElementById('kv-contact-support');
    const requestIpBtn = document.getElementById('kv-request-ip-btn');

    const ICONS = {
        pending: '<svg class="w-5 h-5 text-text-secondary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/></svg>',
        running: '<svg class="w-5 h-5 text-brand animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9" stroke-opacity="0.25"/><path d="M21 12a9 9 0 0 0-9-9"/></svg>',
        pass: '<svg class="w-5 h-5 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>',
        fail: '<svg class="w-5 h-5 text-danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5l5 5M14.5 9.5l-5 5"/></svg>',
    };

    function setCheck(name, state) {
        const li = document.querySelector(`.kv-check[data-check="${name}"]`);
        if (!li) return;
        li.querySelector('.kv-check-icon').innerHTML = ICONS[state] || ICONS.pending;
    }

    function resetChecklist() {
        ['client_key', 'secret_key', 'bearer_token', 'reachable', 'auth', 'ip', 'request'].forEach((c) => setCheck(c, 'pending'));
        failureDetail.classList.add('hidden');
        contactSupport.classList.add('hidden');
        requestIpBtn.classList.add('hidden');
        requestIpBtn.disabled = false;
    }

    function setStatus(kind, title, subtitle) {
        // kind: 'idle' | 'success' | 'failure'
        const chip = { idle: 'bg-surface-muted text-text-secondary', success: 'bg-success-bg text-success', failure: 'bg-danger-bg text-danger' }[kind];
        statusCard.className = 'card mb-5 text-center py-10';
        statusIcon.className = 'inline-flex items-center justify-center w-16 h-16 rounded-full mb-4 ' + chip;
        statusIcon.innerHTML = kind === 'success' ? ICONS.pass.replace('w-5 h-5', 'w-7 h-7')
            : kind === 'failure' ? ICONS.fail.replace('w-5 h-5', 'w-7 h-7')
            : '<svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 19.5 6.5V11c0 5-3.2 8.2-7.5 9.5C7.7 19.2 4.5 16 4.5 11V6.5L12 3.5Z"/></svg>';
        statusTitle.textContent = title;
        statusSubtitle.textContent = subtitle;
    }

    function showFailure(message, showIp) {
        setStatus('failure', 'Verification failed', 'Your API connection could not be confirmed.');
        failureMessage.textContent = message;
        failureDetail.classList.remove('hidden');
        if (showIp) contactSupport.classList.remove('hidden');
    }

    async function refreshLastVerified() {
        const { success, data } = await apiFetch('/api/settings/api-credentials.php');
        if (success && data.last_verified_at) {
            statusMeta.textContent = 'Last verified: ' + formatIST(data.last_verified_at);
        }
    }

    async function runVerification() {
        setButtonLoading(btn, true);
        resetChecklist();
        setStatus('idle', 'Verifying…', 'Running checks against your API credentials.');
        statusMeta.textContent = '';

        const credRes = await apiFetch('/api/settings/api-credentials.php');
        if (!credRes.success) {
            setCheck('client_key', 'fail');
            showFailure(credRes.message || 'Unable to load your API credentials.');
            setButtonLoading(btn, false);
            return;
        }
        const creds = credRes.data;
        setCheck('client_key', creds.client_key ? 'pass' : 'fail');
        setCheck('secret_key', creds.client_key ? 'pass' : 'fail');

        if (!creds.bearer_token) {
            setCheck('bearer_token', 'fail');
            showFailure('No bearer token has been generated yet. Go to API access settings and click "Generate token", then verify again.');
            setButtonLoading(btn, false);
            return;
        }
        setCheck('bearer_token', 'pass');

        setCheck('reachable', 'running');
        let res, body;
        try {
            res = await fetch('/api/v1/balance', { headers: { Authorization: 'Bearer ' + creds.bearer_token } });
        } catch (err) {
            setCheck('reachable', 'fail');
            showFailure('Could not reach the API base URL from your browser. Check your network connection and try again.');
            setButtonLoading(btn, false);
            return;
        }
        setCheck('reachable', 'pass');

        try {
            body = await res.json();
        } catch (err) {
            body = { success: false, message: 'Unexpected response from the server.' };
        }

        setCheck('auth', 'running');

        if (res.status === 401) {
            setCheck('auth', 'fail');
            setCheck('ip', 'fail');
            setCheck('request', 'fail');
            showFailure(body.message || 'Authentication failed — your bearer token may be invalid or revoked.');
        } else if (res.status === 403 && body.data && body.data.ip) {
            setCheck('auth', 'pass');
            setCheck('ip', 'fail');
            setCheck('request', 'fail');
            showFailure('Your server IP address is not whitelisted for this account.\n\nYour IP: ' + body.data.ip, true);
            requestIpBtn.textContent = 'Request to whitelist ' + body.data.ip;
            requestIpBtn.dataset.ip = body.data.ip;
            requestIpBtn.disabled = false;
            requestIpBtn.classList.remove('hidden');
        } else if (!res.ok || !body.success) {
            setCheck('auth', 'fail');
            setCheck('ip', 'fail');
            setCheck('request', 'fail');
            showFailure(body.message || 'The API request did not succeed.');
        } else {
            setCheck('auth', 'pass');
            setCheck('ip', 'pass');
            setCheck('request', 'pass');
            setStatus('success', 'API verified', 'Your API connection is working correctly.');
            await apiFetch('/api/settings/mark-verified.php', { method: 'POST' });
            await refreshLastVerified();
        }

        setButtonLoading(btn, false);
    }

    requestIpBtn.addEventListener('click', async () => {
        const ip = requestIpBtn.dataset.ip;
        if (!ip) return;
        requestIpBtn.disabled = true;
        const { success, message } = await apiFetch('/api/settings/request-api-ip.php', {
            method: 'POST',
            body: { ip_address: ip },
        });
        if (!success) {
            showToast(message || 'Unable to submit that request.', 'error');
            requestIpBtn.disabled = false;
            return;
        }
        showToast(message || 'Request submitted.', 'success');
    });

    btn.addEventListener('click', runVerification);
    refreshLastVerified();
})();
