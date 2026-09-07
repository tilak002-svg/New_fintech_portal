(function () {
    'use strict';
    const { apiFetch, showToast, renderTrendChart, escapeHtml, formatMoney: money, setButtonLoading, openModal, closeModal } = window.Verapay;

    const root = document.getElementById('dashboard-root');
    if (!root) return;
    const isOperator = root.dataset.role === 'admin' || root.dataset.role === 'operator';

    function statusBadgeClass(status) {
        const map = { success: 'badge-success', pending: 'badge-warning', failed: 'badge-danger', cancelled: 'badge-neutral', refunded: 'badge-info' };
        return map[status] || 'badge-neutral';
    }

    function statusBorderClass(status) {
        const map = { success: 'border-l-success', pending: 'border-l-warning', failed: 'border-l-danger', cancelled: 'border-l-neutral', refunded: 'border-l-info' };
        return map[status] || 'border-l-neutral';
    }

    function typeIconChip(type) {
        const tone = type === 'deposit' ? 'icon-chip-success' : 'icon-chip-warning';
        const path = type === 'deposit'
            ? '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8.5 11.5 12 15l3.5-3.5"/>'
            : '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 12.5 12 9l3.5 3.5"/>';
        return `<span class="icon-chip-sm ${tone}"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg></span>`;
    }

    function fillReportGrid(prefix, report) {
        ['total', 'success', 'pending', 'failed'].forEach((key) => {
            const entry = report[key];
            const amountEl = document.getElementById(`${prefix}-${key}-amount`);
            const countEl = document.getElementById(`${prefix}-${key}-count`);
            if (amountEl) amountEl.textContent = money(entry.amount);
            if (countEl) countEl.textContent = `${entry.count} transaction${entry.count === 1 ? '' : 's'}`;
        });
    }

    function renderRecentActivity(data) {
        const tbody = document.getElementById('recent-tbody');
        const colCount = isOperator ? 6 : 5;
        if (!data.recent.length) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h4l2 3h4l2-3h4"/><path d="M5.5 5h13L21 12v6a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18v-6Z"/></svg></span>
                    <p class="empty-state-title">No transactions yet</p>
                    ${isOperator ? '' : '<p class="empty-state-body">PayIns and PayOuts from your integration will appear here.</p><a href="/api-docs" class="btn-primary">View API documentation</a>'}
                </div>
            </td></tr>`;
            return;
        }
        tbody.innerHTML = data.recent.map((t) => `
            <tr class="border-l-4 ${statusBorderClass(t.status)}">
                ${isOperator ? `<td>${escapeHtml(t.user_name)}</td>` : ''}
                <td>
                    <span class="inline-flex items-center gap-2.5">
                        ${typeIconChip(t.type)}
                        <span class="font-mono text-sm">${escapeHtml(t.reference)}</span>
                    </span>
                </td>
                <td class="capitalize">${escapeHtml(t.type)}</td>
                <td class="table-amount">${money(t.amount, t.currency)}</td>
                <td><span class="${statusBadgeClass(t.status)}">${escapeHtml(t.status)}</span></td>
                <td class="text-text-secondary">${new Date(t.created_at).toLocaleDateString()}</td>
            </tr>`).join('');
    }

    function renderGatewayHealth(gateways) {
        const tbody = document.getElementById('gateway-health-tbody');
        if (!tbody) return;
        if (!gateways.length) {
            tbody.innerHTML = `<tr><td colspan="4">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="9" width="7" height="7" rx="1.5"/><rect x="14" y="9" width="7" height="7" rx="1.5"/></svg></span>
                    <p class="empty-state-title">No gateways configured</p>
                    <p class="empty-state-body">Add a payment gateway to begin processing payments.</p>
                    <a href="/admin/gateways" class="btn-primary">Add gateway</a>
                </div>
            </td></tr>`;
            return;
        }
        tbody.innerHTML = gateways.map((g) => {
            let statusBadge;
            if (g.auto_paused) statusBadge = '<span class="badge-warning">Auto-paused</span>';
            else if (g.status === 'active') statusBadge = '<span class="badge-success">Active</span>';
            else statusBadge = '<span class="badge-neutral">Inactive</span>';

            const usage = g.daily_limit_amount
                ? `${money(g.used_today)} / ${money(g.daily_limit_amount)}`
                : `${money(g.used_today)} <span class="text-text-secondary">(no daily limit)</span>`;

            const rate = g.success_rate === null
                ? '<span class="text-text-secondary">—</span>'
                : `<span class="${g.success_rate >= 95 ? 'text-success' : g.success_rate >= 80 ? 'text-warning' : 'text-danger'} font-medium">${g.success_rate}%</span>`;

            return `<tr>
                <td>
                    <span class="block text-md text-text-primary">${escapeHtml(g.display_name)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(g.provider)} · ${g.sandbox_mode ? 'Sandbox' : 'Live'} · priority ${g.priority ?? '—'}</span>
                </td>
                <td>${statusBadge}</td>
                <td class="text-sm">${usage}</td>
                <td class="text-sm">${rate}</td>
            </tr>`;
        }).join('');
    }

    function renderOnboarding(onboarding) {
        const list = document.getElementById('onboarding-checklist');
        if (!list || !onboarding) return;
        const steps = [
            ['account_created', 'Account created'],
            ['api_credentials_generated', 'API credentials generated'],
            ['ip_whitelist_configured', 'IP whitelist configured'],
            ['callback_configured', 'Callback URL configured'],
            ['api_tested', 'API tested'],
            ['production_integration', 'Production integration'],
        ];
        const linkFor = { ip_whitelist_configured: '/settings', callback_configured: '/settings', api_tested: '/api-docs', production_integration: '/key-verification' };
        list.innerHTML = steps.map(([key, label]) => {
            const done = !!onboarding[key];
            const icon = done
                ? '<svg class="w-5 h-5 text-success shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>'
                : '<svg class="w-5 h-5 text-text-secondary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8"/></svg>';
            const linkHtml = !done && linkFor[key] ? ` <a href="${linkFor[key]}" class="text-brand hover:underline">Set this up</a>` : '';
            return `<li class="flex items-center gap-2.5">
                ${icon}
                <span class="text-md ${done ? 'text-text-primary' : 'text-text-secondary'}">${label}</span>
                ${linkHtml}
            </li>`;
        }).join('');
    }

    async function load() {
        const { success, data, message } = await apiFetch('/api/dashboard/summary.php');
        if (!success) {
            const failTarget = document.getElementById('primary-balance-card') || root;
            failTarget.innerHTML = `<div class="text-center py-4">
                <p class="text-md text-text-primary font-medium mb-1">We couldn't load your dashboard.</p>
                <p class="text-sm text-text-secondary mb-4">${escapeHtml(message || 'Please try again.')}</p>
                <button class="btn-secondary" onclick="location.reload()">Try again</button>
            </div>`;
            return;
        }

        if (isOperator) {
            const todayTotal = (parseFloat(data.deposits_report.total.amount) + parseFloat(data.withdrawals_report.total.amount));
            document.getElementById('primary-balance-amount').textContent = money(todayTotal.toFixed(2));
            document.getElementById('primary-balance-sub1-label').textContent = "Today's transactions";
            document.getElementById('primary-balance-sub1-value').textContent = data.today_count;
            document.getElementById('primary-balance-sub2-label').textContent = 'Successful deposits';
            document.getElementById('primary-balance-sub2-value').textContent = data.deposits_report.success.count;

            fillReportGrid('dep', data.deposits_report);
            fillReportGrid('wd', data.withdrawals_report);

            if (data.payins_today) {
                document.getElementById('payins-today-amount').textContent = money(data.payins_today.amount);
                document.getElementById('payins-today-count').textContent = `${data.payins_today.count} PayIn${data.payins_today.count === 1 ? '' : 's'}`;
            }
            if (data.payouts_today) {
                document.getElementById('payouts-today-amount').textContent = money(data.payouts_today.amount);
                document.getElementById('payouts-today-count').textContent = `${data.payouts_today.count} PayOut${data.payouts_today.count === 1 ? '' : 's'}`;
            }

            renderTrendChart(
                document.getElementById('deposits-chart'),
                data.deposits_trend,
                'var(--color-brand)',
                (v) => money(v.toFixed(2)),
                'deposits-chart-title'
            );
            renderTrendChart(
                document.getElementById('withdrawals-chart'),
                data.withdrawals_trend,
                'var(--color-info)',
                (v) => money(v.toFixed(2)),
                'withdrawals-chart-title'
            );

            renderGatewayHealth(data.gateway_health || []);
        } else {
            renderOnboarding(data.onboarding);
            const wallet = data.wallet || { available_balance: '0.00', pending_balance: '0.00', currency: 'INR' };
            document.getElementById('stat-total-payins').textContent = money(data.total_payins.amount);
            document.getElementById('stat-today-payins').textContent = money(data.payins_today.amount);
            document.getElementById('stat-available-balance').textContent = money(wallet.available_balance, wallet.currency);
            document.getElementById('stat-pending-balance').textContent = money(wallet.pending_balance, wallet.currency);
            document.getElementById('stat-total-payouts').textContent = money(data.total_payouts.amount);
            document.getElementById('stat-today-payouts').textContent = money(data.payouts_today.amount);
        }

        renderRecentActivity(data);
    }

    // ---------------- Platform API Base URL (admin only) ----------------
    const baseUrlValueEl = document.getElementById('base-url-value');
    if (baseUrlValueEl) {
        async function loadBaseUrl() {
            const { success, data } = await apiFetch('/api/admin/settings/base-url.php');
            baseUrlValueEl.textContent = success ? data.api_base_url : 'Unable to load';
        }

        document.querySelector('[data-modal-trigger="edit-base-url-modal"]')?.addEventListener('click', async () => {
            const { success, data } = await apiFetch('/api/admin/settings/base-url.php');
            if (success) {
                document.getElementById('base-url-input').value = data.is_override ? data.api_base_url : '';
                document.getElementById('base-url-input').placeholder = data.default_url;
            }
        });

        document.getElementById('base-url-save')?.addEventListener('click', async (e) => {
            const btn = e.currentTarget;
            const errorEl = document.getElementById('base-url-error');
            errorEl.classList.add('hidden');
            setButtonLoading(btn, true);
            const { success, message } = await apiFetch('/api/admin/settings/base-url.php', {
                method: 'POST',
                body: { api_base_url: document.getElementById('base-url-input').value.trim() },
            });
            setButtonLoading(btn, false);
            if (!success) {
                errorEl.textContent = message || 'Unable to save.';
                errorEl.classList.remove('hidden');
                return;
            }
            closeModal('edit-base-url-modal');
            showToast(message || 'Saved.', 'success');
            loadBaseUrl();
        });

        loadBaseUrl();
    }

    load();
})();
