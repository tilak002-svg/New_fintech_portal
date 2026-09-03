(function () {
    'use strict';

    const {
        apiFetch,
        showToast,
        setButtonLoading,
        escapeHtml,
        openModal,
        closeModal,
        formatMoney
    } = window.Verapay;

    const providerNames = {
        razorpay: 'Razorpay',
        payu: 'PayU',
        cashfree: 'Cashfree',
        stripe: 'Stripe',
        paypal: 'PayPal',
        other: 'Other'
    };

    const trashIcon = `
        <svg class="w-4 h-4"
             viewBox="0 0 24 24"
             fill="none"
             stroke="currentColor"
             stroke-width="1.75"
             stroke-linecap="round"
             stroke-linejoin="round">
            <path d="M4 7h16"/>
            <path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            <path d="m17 7-.7 12.1a2 2 0 0 1-2 1.9H9.7a2 2 0 0 1-2-1.9L7 7h10Z"/>
        </svg>
    `;

    const tbody = document.getElementById('gateways-tbody');

    let pendingDeleteId = null;
    let pendingResetId = null;
    let pendingClearPauseId = null;

    // Providers with a real live-order-creation adapter, and the label
    // their public identifier goes by - keep in sync with
    // includes/gateway_providers/dispatch.php's supported provider list.
    const livePublicKeyLabels = {
        razorpay: 'Key ID',
        cashfree: 'Client ID'
    };
    // Providers that split their API across separate sandbox/production
    // base URLs, so the sandbox toggle is meaningful for them.
    const sandboxAwareProviders = ['cashfree'];

    const agProviderSelect = document.getElementById('ag-provider');
    const agPublicKeyField = document.getElementById('ag-public-key-field');
    const agPublicKeyLabel = document.getElementById('ag-public-key-label');
    const agPublicKeyInput = document.getElementById('ag-public-key');
    const agSandboxField = document.getElementById('ag-sandbox-field');
    const agPayoutAccountField = document.getElementById('ag-payout-account-field');

    function syncPublicKeyRequirement() {
        const provider = agProviderSelect.value;
        const needsPublicKey = provider in livePublicKeyLabels;
        agPublicKeyField.classList.toggle('hidden', !needsPublicKey);
        agPublicKeyInput.required = needsPublicKey;
        agPublicKeyLabel.textContent = livePublicKeyLabels[provider] || 'Key ID';
        agSandboxField.classList.toggle('hidden', !sandboxAwareProviders.includes(provider));
        agPayoutAccountField.classList.toggle('hidden', provider !== 'razorpay');
    }
    agProviderSelect.addEventListener('change', syncPublicKeyRequirement);
    syncPublicKeyRequirement();

    function timeLabel(iso) {
        return new Date(iso).toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    async function load() {
        tbody.innerHTML = `
            <tr>
                <td colspan="8"
                    class="text-center py-8 text-text-secondary">
                    Loading gateways…
                </td>
            </tr>
        `;

        const {
            success,
            data,
            message
        } = await apiFetch('/api/admin/gateways/list.php');

        if (!success) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-10">
                        <p class="text-md text-text-primary font-medium mb-1">
                            We couldn't load payment gateways.
                        </p>

                        <p class="text-sm text-text-secondary mb-4">
                            ${escapeHtml(message || 'Please try again.')}
                        </p>

                        <button class="btn-secondary" id="gw-retry">
                            Try again
                        </button>
                    </td>
                </tr>
            `;

            document
                .getElementById('gw-retry')
                ?.addEventListener('click', load);

            return;
        }

        if (!data.gateways.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-10">
                        <p class="text-md text-text-primary font-medium mb-1">
                            No payment gateways configured
                        </p>

                        <p class="text-sm text-text-secondary">
                            Add one to start routing transactions through it.
                        </p>
                    </td>
                </tr>
            `;

            return;
        }

        tbody.innerHTML = data.gateways.map((g) => `
            <tr>
                <td>
                    <span class="flex items-center gap-2">
                        <span class="text-md font-medium text-text-primary">
                            ${escapeHtml(g.display_name)}
                        </span>

                        ${g.is_default
                            ? '<span class="badge-info">Default</span>'
                            : ''}
                    </span>

                    <span class="block text-sm text-text-secondary mt-0.5">
                        Added ${timeLabel(g.created_at)}
                    </span>
                </td>

                <td>
                    ${escapeHtml(providerNames[g.provider] || g.provider)}
                    ${g.live_integration
                        ? `<span class="block text-xs text-text-secondary mt-0.5">${g.sandbox_mode ? 'Sandbox' : 'Live'} · ${g.live_payout ? 'pay-in + payout' : 'pay-in only'}</span>`
                        : ''}
                </td>

                <td class="font-mono text-sm">
                    •••• ${escapeHtml(g.api_key_last4)}
                </td>

                <td>${g.priority}</td>

                <td class="text-sm">
                    ${g.daily_limit_amount === null
                        ? '<span class="text-text-secondary">Unlimited</span>'
                        : `
                            <span class="text-text-primary">
                                ${formatMoney(g.used_today)} / ${formatMoney(g.daily_limit_amount)}
                            </span>
                            <span class="block text-text-secondary mt-0.5">
                                ${formatMoney(g.remaining_today)} remaining · ${g.transaction_count_today} txns today
                            </span>
                        `}
                </td>

                <td>
                    <span class="${g.webhook_configured
                        ? 'badge-success'
                        : 'badge-neutral'}">
                        ${g.webhook_configured ? 'Configured' : 'Not configured'}
                    </span>
                </td>

                <td>
                    <span class="${g.status === 'active'
                        ? 'badge-success'
                        : 'badge-neutral'}">
                        ${escapeHtml(g.status)}
                    </span>

                    ${g.auto_paused
                        ? `
                            <span class="badge-warning block mt-1">
                                Auto-paused
                            </span>
                        `
                        : ''}
                </td>

                <td class="text-right whitespace-nowrap">
                    <div class="inline-flex items-center gap-1.5">

                        <button type="button"
                                class="btn-ghost !px-3 !py-1.5 limits-btn"
                                data-id="${g.id}"
                                data-name="${escapeHtml(g.display_name)}"
                                data-priority="${g.priority}"
                                data-daily-limit="${g.daily_limit_amount ?? ''}">
                            Limits
                        </button>

                        <button type="button"
                                class="btn-ghost !px-3 !py-1.5 webhook-btn"
                                data-id="${g.id}"
                                data-name="${escapeHtml(g.display_name)}"
                                data-url="${escapeHtml(g.webhook_url)}">
                            Webhook
                        </button>

                        ${g.status === 'active'
                            ? `
                                <button type="button"
                                        class="btn-ghost !px-3 !py-1.5 status-btn"
                                        data-id="${g.id}"
                                        data-status="inactive"
                                        data-name="${escapeHtml(g.display_name)}">
                                    Deactivate
                                </button>
                            `
                            : `
                                <button type="button"
                                        class="btn-ghost !px-3 !py-1.5 status-btn"
                                        data-id="${g.id}"
                                        data-status="active"
                                        data-name="${escapeHtml(g.display_name)}">
                                    Activate
                                </button>
                            `}

                        ${g.status === 'active' && !g.is_default
                            ? `
                                <button type="button"
                                        class="btn-secondary !px-3 !py-1.5 default-btn"
                                        data-id="${g.id}"
                                        data-name="${escapeHtml(g.display_name)}">
                                    Set default
                                </button>
                            `
                            : ''}

                        <button type="button"
                                class="btn-ghost !px-3 !py-1.5 rotate-btn"
                                data-id="${g.id}"
                                data-name="${escapeHtml(g.display_name)}"
                                data-provider="${g.provider}"
                                data-sandbox-mode="${g.sandbox_mode}">
                            Rotate key
                        </button>

                        ${g.auto_paused
                            ? `
                                <button type="button"
                                        class="btn-secondary !px-3 !py-1.5 clear-pause-btn"
                                        data-id="${g.id}"
                                        data-name="${escapeHtml(g.display_name)}">
                                    Clear pause
                                </button>
                            `
                            : ''}

                        ${!g.is_default
                            ? `
                                <button type="button"
                                        class="btn-icon delete-btn"
                                        data-id="${g.id}"
                                        data-name="${escapeHtml(g.display_name)}"
                                        aria-label="Remove ${escapeHtml(g.display_name)}">
                                    ${trashIcon}
                                </button>
                            `
                            : ''}
                    </div>
                </td>
            </tr>
        `).join('');

        wireRowActions();
    }

    function wireRowActions() {
        tbody.querySelectorAll('.status-btn').forEach((btn) => {
            btn.addEventListener('click', async () => {
                setButtonLoading(btn, true);

                const {
                    success,
                    message
                } = await apiFetch(
                    '/api/admin/gateways/update-status.php',
                    {
                        method: 'POST',
                        body: {
                            id: btn.dataset.id,
                            status: btn.dataset.status
                        }
                    }
                );

                setButtonLoading(btn, false);

                showToast(
                    message,
                    success ? 'success' : 'error'
                );

                if (success) {
                    load();
                }
            });
        });

        tbody.querySelectorAll('.default-btn').forEach((btn) => {
            btn.addEventListener('click', async () => {
                setButtonLoading(btn, true);

                const {
                    success,
                    message
                } = await apiFetch(
                    '/api/admin/gateways/set-default.php',
                    {
                        method: 'POST',
                        body: {
                            id: btn.dataset.id
                        }
                    }
                );

                setButtonLoading(btn, false);

                showToast(
                    message,
                    success ? 'success' : 'error'
                );

                if (success) {
                    load();
                }
            });
        });

        tbody.querySelectorAll('.rotate-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const provider = btn.dataset.provider;

                document.getElementById('rotate-key-form').dataset.id =
                    btn.dataset.id;

                document.getElementById('rotate-key-target').textContent =
                    `New key for ${btn.dataset.name}.`;

                document.getElementById('rk-key').value = '';
                document.getElementById('rk-public-key').value = '';
                document.getElementById('rk-public-key-field')
                    .classList.toggle('hidden', !(provider in livePublicKeyLabels));
                document.getElementById('rk-public-key-label').textContent =
                    `New ${livePublicKeyLabels[provider] || 'Key ID'}`;

                document.getElementById('rk-payout-account').value = '';
                document.getElementById('rk-payout-account-field')
                    .classList.toggle('hidden', provider !== 'razorpay');

                document.getElementById('rk-sandbox-field')
                    .classList.toggle('hidden', !sandboxAwareProviders.includes(provider));
                document.getElementById('rk-sandbox-mode').checked =
                    btn.dataset.sandboxMode === '1' || btn.dataset.sandboxMode === 'true';

                openModal('rotate-key-modal');
            });
        });

        tbody.querySelectorAll('.limits-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const form = document.getElementById('edit-limits-form');
                form.dataset.id = btn.dataset.id;

                document.getElementById('edit-limits-target').textContent =
                    `${btn.dataset.name}'s selection priority and daily capacity.`;

                document.getElementById('el-priority').value = btn.dataset.priority;
                document.getElementById('el-daily-limit').value = btn.dataset.dailyLimit;
                document.getElementById('el-error').classList.add('hidden');

                openModal('edit-limits-modal');
            });
        });

        tbody.querySelectorAll('.webhook-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                const form = document.getElementById('configure-webhook-form');
                form.dataset.id = btn.dataset.id;

                document.getElementById('cw-url').value = btn.dataset.url;
                document.getElementById('cw-secret').value = '';
                document.getElementById('cw-error').classList.add('hidden');

                openModal('configure-webhook-modal');
            });
        });

        tbody.querySelectorAll('.clear-pause-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                pendingClearPauseId = btn.dataset.id;

                document.getElementById('clear-pause-body').textContent =
                    `${btn.dataset.name} will be eligible for selection again immediately, and its failure count will be reset to zero.`;

                openModal('clear-pause-modal');
            });
        });

        tbody.querySelectorAll('.delete-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                pendingDeleteId = btn.dataset.id;

                document.getElementById('delete-gateway-body').textContent =
                    `${btn.dataset.name} will be permanently removed. This cannot be undone.`;

                openModal('delete-gateway-modal');
            });
        });
    }

    document
        .getElementById('add-gateway-form')
        .addEventListener('submit', async (e) => {
            e.preventDefault();

            const errorEl = document.getElementById('ag-error');
            errorEl.classList.add('hidden');

            const submitBtn = document.getElementById('ag-submit');

            setButtonLoading(submitBtn, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/create.php',
                {
                    method: 'POST',
                    body: {
                        display_name: document
                            .getElementById('ag-name')
                            .value
                            .trim(),

                        provider: document
                            .getElementById('ag-provider')
                            .value,

                        api_key: document
                            .getElementById('ag-key')
                            .value
                            .trim(),

                        public_key: document
                            .getElementById('ag-public-key')
                            .value
                            .trim(),

                        payout_account_number: document
                            .getElementById('ag-payout-account')
                            .value
                            .trim(),

                        sandbox_mode: document
                            .getElementById('ag-sandbox-mode')
                            .checked
                    }
                }
            );

            setButtonLoading(submitBtn, false);

            if (!success) {
                errorEl.textContent =
                    message || 'Unable to add this gateway.';

                errorEl.classList.remove('hidden');

                return;
            }

            closeModal('add-gateway-modal');

            e.target.reset();
            syncPublicKeyRequirement();

            showToast(message, 'success');

            load();
        });

    document
        .getElementById('rotate-key-form')
        .addEventListener('submit', async (e) => {
            e.preventDefault();

            const errorEl = document.getElementById('rk-error');
            errorEl.classList.add('hidden');

            const id = e.target.dataset.id;
            const submitBtn = document.getElementById('rk-submit');

            setButtonLoading(submitBtn, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/rotate-key.php',
                {
                    method: 'POST',
                    body: {
                        id,
                        api_key: document
                            .getElementById('rk-key')
                            .value
                            .trim(),

                        public_key: document
                            .getElementById('rk-public-key')
                            .value
                            .trim(),

                        payout_account_number: document
                            .getElementById('rk-payout-account')
                            .value
                            .trim(),

                        sandbox_mode: document
                            .getElementById('rk-sandbox-mode')
                            .checked
                    }
                }
            );

            setButtonLoading(submitBtn, false);

            if (!success) {
                errorEl.textContent =
                    message || 'Unable to rotate this key.';

                errorEl.classList.remove('hidden');

                return;
            }

            closeModal('rotate-key-modal');

            showToast(message, 'success');

            load();
        });

    document
        .getElementById('el-reset-usage')
        .addEventListener('click', () => {
            pendingResetId = document.getElementById('edit-limits-form').dataset.id;

            closeModal('edit-limits-modal');
            openModal('reset-usage-modal');
        });

    document
        .getElementById('edit-limits-form')
        .addEventListener('submit', async (e) => {
            e.preventDefault();

            const errorEl = document.getElementById('el-error');
            errorEl.classList.add('hidden');

            const id = e.target.dataset.id;
            const submitBtn = document.getElementById('el-submit');
            const dailyLimitRaw = document.getElementById('el-daily-limit').value.trim();

            setButtonLoading(submitBtn, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/update-limits.php',
                {
                    method: 'POST',
                    body: {
                        id,
                        priority: document.getElementById('el-priority').value,
                        daily_limit_amount: dailyLimitRaw === '' ? null : dailyLimitRaw
                    }
                }
            );

            setButtonLoading(submitBtn, false);

            if (!success) {
                errorEl.textContent = message || 'Unable to update this gateway\'s limits.';
                errorEl.classList.remove('hidden');
                return;
            }

            closeModal('edit-limits-modal');

            showToast(message, 'success');

            load();
        });

    document
        .getElementById('reset-usage-confirm-btn')
        .addEventListener('click', async function () {
            if (!pendingResetId) {
                return;
            }

            setButtonLoading(this, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/reset-usage.php',
                {
                    method: 'POST',
                    body: {
                        id: pendingResetId
                    }
                }
            );

            setButtonLoading(this, false);

            closeModal('reset-usage-modal');

            showToast(
                message,
                success ? 'success' : 'error'
            );

            if (success) {
                pendingResetId = null;
                load();
            }
        });

    document
        .getElementById('clear-pause-confirm-btn')
        .addEventListener('click', async function () {
            if (!pendingClearPauseId) {
                return;
            }

            setButtonLoading(this, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/clear-pause.php',
                {
                    method: 'POST',
                    body: {
                        id: pendingClearPauseId
                    }
                }
            );

            setButtonLoading(this, false);

            closeModal('clear-pause-modal');

            showToast(
                message,
                success ? 'success' : 'error'
            );

            if (success) {
                pendingClearPauseId = null;
                load();
            }
        });

    document
        .getElementById('configure-webhook-form')
        .addEventListener('submit', async (e) => {
            e.preventDefault();

            const errorEl = document.getElementById('cw-error');
            errorEl.classList.add('hidden');

            const id = e.target.dataset.id;
            const submitBtn = document.getElementById('cw-submit');

            setButtonLoading(submitBtn, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/set-webhook-secret.php',
                {
                    method: 'POST',
                    body: {
                        id,
                        webhook_secret: document
                            .getElementById('cw-secret')
                            .value
                            .trim()
                    }
                }
            );

            setButtonLoading(submitBtn, false);

            if (!success) {
                errorEl.textContent = message || 'Unable to save this webhook secret.';
                errorEl.classList.remove('hidden');
                return;
            }

            closeModal('configure-webhook-modal');

            showToast(message, 'success');

            load();
        });

    document
        .getElementById('delete-gateway-confirm-btn')
        .addEventListener('click', async function () {
            if (!pendingDeleteId) {
                return;
            }

            setButtonLoading(this, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/gateways/delete.php',
                {
                    method: 'POST',
                    body: {
                        id: pendingDeleteId
                    }
                }
            );

            setButtonLoading(this, false);

            closeModal('delete-gateway-modal');

            showToast(
                message,
                success ? 'success' : 'error'
            );

            if (success) {
                pendingDeleteId = null;
                load();
            }
        });

    load();
})();