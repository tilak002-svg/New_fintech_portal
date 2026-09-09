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

    // Raw SVG matching icons.php's 'gateway' path, for the JS-rendered
    // empty state so it's pixel-identical to the server-rendered one this
    // replaces on re-render (page.php's own <?= icon('gateway', ...) ?>).
    const GATEWAY_EMPTY_ICON_SVG = '<svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="9" width="7" height="7" rx="1.5"/><rect x="14" y="9" width="7" height="7" rx="1.5"/><path d="M10 12.5h4"/><path d="M6.5 9V6a1.5 1.5 0 0 1 1.5-1.5h8A1.5 1.5 0 0 1 17.5 6v3"/></svg>';

    // Identity color, not status color — this is "which gateway", not
    // "what happened", so it deliberately does NOT reuse the
    // success/warning/danger/neutral status palette those colors mean
    // elsewhere on this page (a gateway slice colored "danger" would
    // misread as broken). Fixed order, never cycled/reassigned per filter.
    const GATEWAY_SHARE_COLORS = ['var(--color-brand)', 'var(--color-info)', 'var(--color-success)', 'var(--color-warning)', 'var(--color-neutral)', 'var(--color-danger)'];

    /**
     * All-gateways settled-amount share, as a donut — the "everything at
     * once" companion to the trend chart's "one gateway in detail" view.
     * Synchronous (gateway_health is already loaded, no fetch of its own),
     * so it never needs its own loading state beyond the initial skeleton.
     */
    function renderGatewayShare(gateways) {
        const el = document.getElementById('gateway-analytics-share');
        if (!el) return;

        const withAmount = gateways
            .map((g) => ({ id: g.id, display_name: g.display_name, amount: parseFloat(g.success_amount || '0') }))
            .filter((g) => g.amount > 0);
        const total = withAmount.reduce((sum, g) => sum + g.amount, 0);

        if (!withAmount.length || total <= 0) {
            el.innerHTML = `<div class="empty-state !py-6 h-full justify-center">
                <span class="empty-state-icon">${GATEWAY_EMPTY_ICON_SVG}</span>
                <p class="empty-state-title">No settled volume yet</p>
                <p class="empty-state-body">Each gateway's share of settled amount will appear here once payments start settling.</p>
            </div>`;
            return;
        }

        const size = 180;
        const radius = 70;
        const strokeWidth = 24;
        const circumference = 2 * Math.PI * radius;
        const center = size / 2;

        let offset = 0;
        const segments = withAmount.map((g, i) => {
            const pct = g.amount / total;
            const dash = pct * circumference;
            // 2px surface gap between segments, same rule as the meter bars
            // elsewhere on this page — separation by gap, not a border.
            const gapped = Math.max(dash - 2, 0);
            const color = GATEWAY_SHARE_COLORS[i % GATEWAY_SHARE_COLORS.length];
            const markup = `<circle cx="${center}" cy="${center}" r="${radius}" fill="none" stroke="${color}" stroke-width="${strokeWidth}"
                stroke-dasharray="${gapped.toFixed(2)} ${(circumference - gapped).toFixed(2)}" stroke-dashoffset="${(-offset).toFixed(2)}"
                transform="rotate(-90 ${center} ${center})"><title>${escapeHtml(g.display_name)}: ${money(g.amount.toFixed(2))} (${(pct * 100).toFixed(1)}%)</title></circle>`;
            offset += dash;
            return markup;
        }).join('');

        const legend = withAmount.map((g, i) => `
            <span class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full shrink-0" style="background-color:${GATEWAY_SHARE_COLORS[i % GATEWAY_SHARE_COLORS.length]}"></span>
                <span class="text-text-secondary truncate min-w-0">${escapeHtml(g.display_name)}</span>
                <span class="text-text-primary font-medium ml-auto shrink-0">${((g.amount / total) * 100).toFixed(0)}%</span>
            </span>`).join('');

        el.innerHTML = `
            <p class="text-sm font-medium text-text-secondary mb-3">Settled amount share by gateway</p>
            <div class="flex items-center gap-6">
                <svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" class="shrink-0" role="img" aria-label="Settled amount share by gateway, ${withAmount.length} gateways, ${money(total.toFixed(2))} total">
                    ${segments}
                    <text x="${center}" y="${center - 4}" text-anchor="middle" font-size="11" fill="var(--color-text-secondary)">Total</text>
                    <text x="${center}" y="${center + 14}" text-anchor="middle" font-size="13" font-weight="600" fill="var(--color-text-primary)">${money(total.toFixed(2))}</text>
                </svg>
                <div class="flex-1 min-w-0 space-y-2">${legend}</div>
            </div>`;
    }

    /**
     * Per-gateway drill-down — same trend-chart treatment as Deposit/
     * Withdrawal analytics below it (renderTrendChart), scoped by whichever
     * gateway the dropdown has selected. The summary stats reuse the
     * gateway_health entry load() already fetched — only the 7-day trend
     * needs its own request, re-fired on every selection change.
     */
    function initGatewayAnalytics(gateways) {
        const select = document.getElementById('gateway-analytics-select');
        const summaryEl = document.getElementById('gateway-analytics-summary');
        const chartEl = document.getElementById('gateway-analytics-chart');
        if (!select || !chartEl) return;

        const byId = Object.fromEntries(gateways.map((g) => [String(g.id), g]));
        const previousValue = select.value;
        select.innerHTML = '<option value="">Select a gateway…</option>'
            + gateways.map((g) => `<option value="${g.id}">${escapeHtml(g.display_name)}</option>`).join('');
        if (previousValue && byId[previousValue]) {
            select.value = previousValue;
        } else if (gateways.length) {
            // Default to the highest-priority ACTIVE gateway — gateway_health
            // is ordered priority ASC (see summary.php) but priority alone
            // ignores status, and select_and_reserve_gateway() (the real
            // routing engine) only ever considers active gateways. Picking
            // an inactive one here would default to a gateway that could
            // never actually handle a PayIn. Falls back to the first
            // gateway overall only if none are active.
            const defaultGateway = gateways.find((g) => g.status === 'active') || gateways[0];
            select.value = String(defaultGateway.id);
        }

        renderGatewayShare(gateways);

        function showEmptyState() {
            summaryEl.style.visibility = 'hidden';
            chartEl.innerHTML = `<div class="empty-state">
                <span class="empty-state-icon">${GATEWAY_EMPTY_ICON_SVG}</span>
                <p class="empty-state-title">Select a gateway</p>
                <p class="empty-state-body">Pick a gateway above to see its individual transaction summary.</p>
            </div>`;
        }

        async function loadFor(gatewayId) {
            const g = byId[gatewayId];
            if (!g) { showEmptyState(); return; }

            const rateColor = g.success_rate === null ? 'text-text-secondary'
                : g.success_rate >= 95 ? 'text-success' : g.success_rate >= 80 ? 'text-warning' : 'text-danger';
            summaryEl.style.visibility = 'visible';
            summaryEl.innerHTML = `
                <div>
                    <p class="text-sm text-text-secondary">Total transactions</p>
                    <p class="text-lg font-semibold text-text-primary">${g.total_count}</p>
                </div>
                <div class="pl-6 border-l border-border">
                    <p class="text-sm text-text-secondary">Success rate</p>
                    <p class="text-lg font-semibold ${rateColor}">${g.success_rate === null ? '—' : g.success_rate + '%'}</p>
                </div>
                <div class="pl-6 border-l border-border">
                    <p class="text-sm text-text-secondary">Settled amount</p>
                    <p class="text-lg font-semibold text-text-primary">${money(g.success_amount || '0.00')}</p>
                </div>`;

            chartEl.innerHTML = '<div class="skeleton h-[265px] w-full rounded-sm"></div>';
            const { success, data: trendData } = await apiFetch(`/api/dashboard/gateway-trend.php?gateway_id=${encodeURIComponent(gatewayId)}`);
            // The dropdown may have changed again while this request was in
            // flight — never paint a stale trend over a newer selection.
            if (!success || select.value !== gatewayId) return;
            renderTrendChart(chartEl, trendData.trend, 'var(--color-brand)', (v) => money(v.toFixed(2)), 'gateway-analytics-chart-title');
        }

        select.addEventListener('change', () => {
            if (select.value) loadFor(select.value); else showEmptyState();
        });

        if (select.value) loadFor(select.value);
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

            initGatewayAnalytics(data.gateway_health || []);
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
