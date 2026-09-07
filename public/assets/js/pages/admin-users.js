(function () {
    'use strict';

    const {
        apiFetch,
        showToast,
        setButtonLoading,
        escapeHtml,
        openModal,
        closeModal
    } = window.Verapay;

    const form = document.getElementById('filters-form');
    const tbody = document.getElementById('users-tbody');
    const pagination = document.getElementById('users-pagination');

    const addCustomerForm = document.getElementById('add-customer-form');
    const addCustomerSubmit = document.getElementById('ac-submit');
    const addCustomerError = document.getElementById('ac-error');

    let searchDebounce;
    let pendingTarget = null;

    function avatarMarkup(u) {
        const initials = escapeHtml(u.avatar_initials || u.name.slice(0, 2));
        const gender = (u.gender === 'male' || u.gender === 'female') ? u.gender : (Number(u.id) % 2 === 0 ? 'female' : 'male');
        const folder = gender === 'male' ? 'men' : 'women';
        const idx = Number(u.id) % 100;
        return `<span class="avatar-chip-sm !bg-transparent relative">
            <img src="https://randomuser.me/api/portraits/${folder}/${idx}.jpg" alt="" class="absolute inset-0 w-full h-full rounded-full object-cover" loading="lazy" referrerpolicy="no-referrer"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <span class="hidden absolute inset-0 rounded-full items-center justify-center bg-brand-muted text-brand-emphasis">${initials}</span>
        </span>`;
    }

    function buildQuery(page) {
        const params = new URLSearchParams(new FormData(form));

        [...params.keys()].forEach((key) => {
            if (!params.get(key)) {
                params.delete(key);
            }
        });

        params.set('page', page);
        params.set('per_page', 15);

        return params.toString();
    }

    async function load(page = 1) {
        tbody.innerHTML = `
            <tr>
                <td colspan="5"
                    class="text-center py-8 text-text-secondary">
                    Loading customers…
                </td>
            </tr>
        `;

        const {
            success,
            data,
            message
        } = await apiFetch(
            '/api/admin/users/list.php?' + buildQuery(page)
        );

        if (!success) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                            <p class="empty-state-title">We couldn't load customers.</p>
                            <p class="empty-state-body">${escapeHtml(message || 'Please try again.')}</p>
                            <button class="btn-secondary" id="users-retry">Try again</button>
                        </div>
                    </td>
                </tr>
            `;

            document
                .getElementById('users-retry')
                ?.addEventListener('click', () => load(page));

            pagination.innerHTML = '';

            return;
        }

        if (!data.users.length) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.5-4.5"/></svg></span>
                            <p class="empty-state-title">No customers match these filters</p>
                            <p class="empty-state-body">Try adjusting your search or status filter.</p>
                        </div>
                    </td>
                </tr>
            `;

            pagination.innerHTML = '';

            return;
        }

        tbody.innerHTML = data.users.map((u) => `
            <tr class="border-l-4 ${u.status === 'active' ? 'border-l-success' : 'border-l-danger'}">
                <td>
                    <span class="flex items-center gap-2.5">
                        ${avatarMarkup(u)}

                        <span class="min-w-0">
                            <span class="block text-md font-medium text-text-primary truncate">
                                ${escapeHtml(u.name)}
                            </span>

                            <span class="block text-sm text-text-secondary truncate">
                                ${escapeHtml(u.email)}
                            </span>
                        </span>
                    </span>
                </td>

                <td class="capitalize">
                    ${escapeHtml(u.role)}
                </td>

                <td>
                    <span class="${u.status === 'active'
                        ? 'badge-success'
                        : 'badge-danger'}">
                        ${escapeHtml(u.status)}
                    </span>
                </td>

                <td class="text-text-secondary whitespace-nowrap">
                    ${new Date(u.created_at).toLocaleDateString()}
                </td>

                <td class="text-right">
                    <span class="inline-flex items-center gap-2">
                        ${u.role === 'customer'
                            ? `
                                <a href="/admin/kyc-review?user_id=${u.id}"
                                   class="btn-secondary !px-3 !py-2 inline-flex items-center gap-1.5"
                                   title="${u.kyc_uploaded_count}/${u.kyc_total_types} documents uploaded${u.kyc_pending_count ? `, ${u.kyc_pending_count} pending review` : ''}">
                                    KYC
                                    ${u.kyc_pending_count > 0
                                        ? `<span class="badge-warning !px-1.5 !py-0.5 !text-xs">${u.kyc_pending_count}</span>`
                                        : ''}
                                </a>

                                <button type="button"
                                        class="btn-ghost !px-3 !py-2 api-ips-btn"
                                        data-id="${u.id}"
                                        data-name="${escapeHtml(u.name)}">
                                    API access
                                </button>
                            `
                            : ''}

                        ${u.status === 'active'
                            ? `
                                <button type="button"
                                        class="btn-danger !px-3 !py-2 suspend-btn"
                                        data-id="${u.id}"
                                        data-name="${escapeHtml(u.name)}">
                                    Suspend
                                </button>
                            `
                            : `
                                <button type="button"
                                        class="btn-secondary !px-3 !py-2 reactivate-btn"
                                        data-id="${u.id}"
                                        data-name="${escapeHtml(u.name)}">
                                    Reactivate
                                </button>
                            `}
                    </span>
                </td>
            </tr>
        `).join('');

        tbody
            .querySelectorAll('.suspend-btn')
            .forEach((btn) => {
                btn.addEventListener('click', () => {
                    pendingTarget = {
                        id: btn.dataset.id,
                        name: btn.dataset.name,
                        page
                    };

                    document
                        .getElementById('suspend-modal-body')
                        .textContent =
                        `${btn.dataset.name} will be signed out immediately and unable to access Verapay until reactivated.`;

                    openModal('suspend-modal');
                });
            });

        tbody
            .querySelectorAll('.reactivate-btn')
            .forEach((btn) => {
                btn.addEventListener('click', () => {
                    pendingTarget = {
                        id: btn.dataset.id,
                        name: btn.dataset.name,
                        page
                    };

                    document
                        .getElementById('reactivate-modal-body')
                        .textContent =
                        `${btn.dataset.name} will regain access to Verapay and can sign in again.`;

                    openModal('reactivate-modal');
                });
            });

        tbody
            .querySelectorAll('.api-ips-btn')
            .forEach((btn) => {
                btn.addEventListener('click', () => {
                    openApiIpsModal(btn.dataset.id, btn.dataset.name);
                });
            });

        const {
            page: currentPage,
            total_pages,
            total
        } = data.pagination;

        pagination.innerHTML = `
            <span class="text-sm text-text-secondary">
                Showing page ${currentPage} of ${total_pages} (${total} total)
            </span>

            <div class="flex items-center gap-2">
                <button type="button"
                        class="btn-secondary !px-4 !py-2"
                        id="users-prev"
                        ${currentPage <= 1 ? 'disabled' : ''}>
                    Previous
                </button>

                <button type="button"
                        class="btn-secondary !px-4 !py-2"
                        id="users-next"
                        ${currentPage >= total_pages ? 'disabled' : ''}>
                    Next
                </button>
            </div>
        `;

        document
            .getElementById('users-prev')
            ?.addEventListener('click', () => load(currentPage - 1));

        document
            .getElementById('users-next')
            ?.addEventListener('click', () => load(currentPage + 1));
    }

    addCustomerForm?.addEventListener('submit', async (event) => {
        event.preventDefault();

        addCustomerError.classList.add('hidden');
        addCustomerError.textContent = '';

        const name = document
            .getElementById('ac-name')
            .value
            .trim();

        const email = document
            .getElementById('ac-email')
            .value
            .trim();

        const password = document
            .getElementById('ac-password')
            .value;

        const gender = document
            .getElementById('ac-gender')
            .value;

        if (!name) {
            addCustomerError.textContent = 'Please enter the customer name.';
            addCustomerError.classList.remove('hidden');
            return;
        }

        if (!email) {
            addCustomerError.textContent = 'Please enter the customer email.';
            addCustomerError.classList.remove('hidden');
            return;
        }

        if (password.length < 8) {
            addCustomerError.textContent =
                'Password must contain at least 8 characters.';

            addCustomerError.classList.remove('hidden');

            return;
        }

        setButtonLoading(addCustomerSubmit, true);

        const {
            success,
            message
        } = await apiFetch(
            '/api/admin/users/create.php',
            {
                method: 'POST',
                body: {
                    name,
                    email,
                    password,
                    gender
                }
            }
        );

        setButtonLoading(addCustomerSubmit, false);

        if (!success) {
            addCustomerError.textContent =
                message || 'Unable to create the customer.';

            addCustomerError.classList.remove('hidden');

            return;
        }

        closeModal('add-customer-modal');

        document.getElementById('cr-login-url').value = window.location.origin + '/login';
        document.getElementById('cr-email').value = email;
        document.getElementById('cr-password').value = password;
        openModal('credentials-reveal-modal');

        addCustomerForm.reset();

        showToast(
            message || 'Customer created successfully.',
            'success'
        );

        load(1);
    });

    // Per-field copy buttons + "copy all" in the post-creation credentials
    // reveal modal — same visual pattern as .aa-copy-btn on the API Access
    // page, scoped to this modal's own fields.
    document.querySelectorAll('.cr-copy-btn').forEach((btn) => {
        const defaultIcon = btn.querySelector('.cr-copy-icon-default');
        const copiedIcon = btn.querySelector('.cr-copy-icon-copied');
        btn.addEventListener('click', async () => {
            const target = document.getElementById(btn.dataset.copyTarget);
            if (!target || !target.value) return;
            try {
                await navigator.clipboard.writeText(target.value);
            } catch (err) {
                showToast('Unable to copy — your browser blocked clipboard access.', 'error');
                return;
            }
            defaultIcon?.classList.add('hidden');
            copiedIcon?.classList.remove('hidden');
            setTimeout(() => {
                defaultIcon?.classList.remove('hidden');
                copiedIcon?.classList.add('hidden');
            }, 1800);
        });
    });

    document.getElementById('cr-copy-all')?.addEventListener('click', async () => {
        const text = [
            `Login URL: ${document.getElementById('cr-login-url').value}`,
            `Email: ${document.getElementById('cr-email').value}`,
            `Temporary password: ${document.getElementById('cr-password').value}`,
        ].join('\n');
        try {
            await navigator.clipboard.writeText(text);
            showToast('Copied to clipboard.', 'success');
        } catch (err) {
            showToast('Unable to copy — your browser blocked clipboard access.', 'error');
        }
    });

    document
        .getElementById('suspend-confirm-btn')
        .addEventListener('click', async function () {
            if (!pendingTarget) {
                return;
            }

            setButtonLoading(this, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/users/suspend.php',
                {
                    method: 'POST',
                    body: {
                        user_id: pendingTarget.id
                    }
                }
            );

            setButtonLoading(this, false);

            closeModal('suspend-modal');

            showToast(
                message,
                success ? 'success' : 'error'
            );

            if (success) {
                load(pendingTarget.page);
            }

            pendingTarget = null;
        });

    document
        .getElementById('reactivate-confirm-btn')
        .addEventListener('click', async function () {
            if (!pendingTarget) {
                return;
            }

            setButtonLoading(this, true);

            const {
                success,
                message
            } = await apiFetch(
                '/api/admin/users/reactivate.php',
                {
                    method: 'POST',
                    body: {
                        user_id: pendingTarget.id
                    }
                }
            );

            setButtonLoading(this, false);

            closeModal('reactivate-modal');

            showToast(
                message,
                success ? 'success' : 'error'
            );

            if (success) {
                load(pendingTarget.page);
            }

            pendingTarget = null;
        });

    let apiIpsTargetId = null;

    async function loadApiIps() {
        const { success, data, message } = await apiFetch(`/api/admin/users/api-ips.php?user_id=${apiIpsTargetId}`);

        if (!success) {
            showToast(message || 'Unable to load API access.', 'error');
            return;
        }

        document.getElementById('api-ips-no-creds').classList.toggle('hidden', data.has_api_credentials);
        document.getElementById('api-ips-body').classList.toggle('hidden', !data.has_api_credentials);

        if (data.has_api_credentials) {
            document.getElementById('api-ips-client-key').textContent = data.client_key;
            document.getElementById('api-ips-token-status').innerHTML = data.has_bearer_token
                ? `<span class="badge-success">Generated</span> <span class="text-xs text-text-secondary block mt-0.5">${new Date(data.bearer_token_generated_at).toLocaleString()}</span>`
                : '<span class="badge-neutral">Not generated</span>';
            document.getElementById('api-ips-payin-url').innerHTML = data.payin_callback_url
                ? escapeHtml(data.payin_callback_url)
                : '<span class="text-text-secondary">Not set</span>';
            document.getElementById('api-ips-payout-url').innerHTML = data.payout_callback_url
                ? escapeHtml(data.payout_callback_url)
                : '<span class="text-text-secondary">Not set</span>';
        }

        const list = document.getElementById('api-ips-list');
        if (!data.whitelisted_ips.length) {
            list.innerHTML = '<li class="text-sm text-text-secondary rounded-sm border border-dashed border-border px-3 py-3 text-center">No IPs whitelisted yet.</li>';
        } else {
            list.innerHTML = data.whitelisted_ips.map((row) => `
                <li class="flex items-center justify-between gap-3 rounded-sm border border-border px-3 py-2.5">
                    <span class="min-w-0">
                        <span class="block font-mono text-sm text-text-primary">${escapeHtml(row.ip_address)}</span>
                        <span class="block text-xs text-text-secondary">Added ${new Date(row.created_at).toLocaleDateString()}${row.added_by_name ? ` by ${escapeHtml(row.added_by_name)}` : ''}</span>
                    </span>
                    <button type="button" class="btn-icon shrink-0 text-lg leading-none" data-remove-ip="${row.id}" aria-label="Remove ${escapeHtml(row.ip_address)}">×</button>
                </li>`).join('');

            list.querySelectorAll('[data-remove-ip]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    const { success: removeSuccess, message: removeMessage } = await apiFetch(
                        '/api/admin/users/remove-api-ip.php',
                        { method: 'POST', body: { id: btn.dataset.removeIp } }
                    );
                    if (!removeSuccess) {
                        showToast(removeMessage || 'Unable to remove that IP.', 'error');
                        btn.disabled = false;
                        return;
                    }
                    showToast('IP removed.', 'success');
                    loadApiIps();
                });
            });
        }
    }

    function openApiIpsModal(userId, name) {
        apiIpsTargetId = userId;
        document.getElementById('api-ips-target').textContent = `Manage which IPs can use ${name}'s API token.`;
        document.getElementById('api-ips-new').value = '';
        document.getElementById('api-ips-error').classList.add('hidden');
        openModal('api-ips-modal');
        loadApiIps();
    }

    document.getElementById('api-ips-add').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const input = document.getElementById('api-ips-new');
        const errorEl = document.getElementById('api-ips-error');
        errorEl.classList.add('hidden');

        if (!input.value.trim()) {
            errorEl.textContent = 'Enter an IP address first.';
            errorEl.classList.remove('hidden');
            return;
        }

        setButtonLoading(btn, true);
        const { success, message } = await apiFetch('/api/admin/users/add-api-ip.php', {
            method: 'POST',
            body: { user_id: apiIpsTargetId, ip_address: input.value.trim() },
        });
        setButtonLoading(btn, false);

        if (!success) {
            errorEl.textContent = message || 'Unable to add that IP.';
            errorEl.classList.remove('hidden');
            return;
        }

        input.value = '';
        showToast('IP whitelisted.', 'success');
        loadApiIps();
    });

    form.addEventListener('input', (event) => {
        if (event.target.id === 'f-search') {
            clearTimeout(searchDebounce);

            searchDebounce = setTimeout(() => {
                load(1);
            }, 350);
        }
    });

    form.addEventListener('change', (event) => {
        if (event.target.id !== 'f-search') {
            load(1);
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        load(1);
    });

    load(1);
})();