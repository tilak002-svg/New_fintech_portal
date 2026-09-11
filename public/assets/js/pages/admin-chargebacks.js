(function () {
    'use strict';
    const { apiFetch, escapeHtml, formatMoney: money, openModal, formatIST } = window.Verapay;

    const form = document.getElementById('filters-form');
    const tbody = document.getElementById('cb-tbody');
    const pagination = document.getElementById('cb-pagination');
    const colCount = 8;
    let searchDebounce;

    function statusBadgeClass(status) {
        const map = { open: 'badge-warning', pending: 'badge-warning', won: 'badge-success', lost: 'badge-danger', reversed: 'badge-info', cancelled: 'badge-neutral' };
        return map[status] || 'badge-neutral';
    }

    function statusBorderClass(status) {
        const map = { open: 'border-l-warning', pending: 'border-l-warning', won: 'border-l-success', lost: 'border-l-danger', reversed: 'border-l-info', cancelled: 'border-l-neutral' };
        return map[status] || 'border-l-neutral';
    }

    function buildQuery(page) {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((k) => { if (!params.get(k)) params.delete(k); });
        params.set('page', page);
        params.set('per_page', 15);
        return params.toString();
    }

    async function load(page = 1) {
        tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-8 text-text-secondary">Loading chargebacks…</td></tr>`;
        const { success, data, message } = await apiFetch('/api/admin/chargebacks/list.php?' + buildQuery(page));

        if (!success) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                    <p class="empty-state-title">We couldn't load chargebacks.</p>
                    <p class="empty-state-body">${escapeHtml(message || 'Please try again.')}</p>
                    <button class="btn-secondary" id="cb-retry">Try again</button>
                </div>
            </td></tr>`;
            document.getElementById('cb-retry')?.addEventListener('click', () => load(page));
            pagination.innerHTML = '';
            return;
        }

        if (!data.chargebacks.length) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg></span>
                    <p class="empty-state-title">No chargebacks found</p>
                    <p class="empty-state-body">Try adjusting your filters, or no disputes have been raised yet.</p>
                </div>
            </td></tr>`;
            pagination.innerHTML = '';
            return;
        }

        tbody.innerHTML = data.chargebacks.map((cb) => `
            <tr class="border-l-4 ${statusBorderClass(cb.status)}">
                <td><span class="font-mono text-sm">CB-${cb.id}</span></td>
                <td>
                    <span class="block text-md text-text-primary">${escapeHtml(cb.user_name)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(cb.user_email)}</span>
                </td>
                <td>
                    <span class="block font-mono text-sm">${escapeHtml(cb.transaction_reference)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(cb.merchant_order_id || '—')}</span>
                </td>
                <td class="text-sm">${escapeHtml(cb.gateway_name || cb.provider)}</td>
                <td class="table-amount">
                    ${money(cb.amount, cb.currency)}
                    <span class="block text-sm text-text-secondary">+${money(cb.fee, cb.currency)} fee</span>
                </td>
                <td><span class="${statusBadgeClass(cb.status)}">${escapeHtml(cb.status)}</span></td>
                <td class="text-text-secondary whitespace-nowrap">${formatIST(cb.created_at)}</td>
                <td><button type="button" class="btn-icon" data-view-cb="${cb.id}" aria-label="View chargeback details"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.75"/></svg></button></td>
            </tr>`).join('');

        tbody.querySelectorAll('[data-view-cb]').forEach((btn) => {
            btn.addEventListener('click', () => viewDetail(btn.dataset.viewCb));
        });

        const { page: p, total_pages, total } = data.pagination;
        pagination.innerHTML = `
            <span class="text-sm text-text-secondary">Showing page ${p} of ${total_pages} (${total} total)</span>
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary !px-4 !py-2" id="cb-prev" ${p <= 1 ? 'disabled' : ''}>Previous</button>
                <button type="button" class="btn-secondary !px-4 !py-2" id="cb-next" ${p >= total_pages ? 'disabled' : ''}>Next</button>
            </div>`;
        document.getElementById('cb-prev')?.addEventListener('click', () => load(p - 1));
        document.getElementById('cb-next')?.addEventListener('click', () => load(p + 1));
    }

    async function viewDetail(id) {
        const body = document.getElementById('cb-detail-body');
        body.innerHTML = '<p class="text-text-secondary">Loading…</p>';
        openModal('cb-detail-modal');

        const { success, data, message } = await apiFetch('/api/chargebacks/detail.php?id=' + encodeURIComponent(id));
        if (!success) {
            body.innerHTML = `<p class="field-error">${escapeHtml(message || 'Unable to load this chargeback.')}</p>`;
            return;
        }

        const cb = data.chargeback;
        body.innerHTML = `
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div><p class="field-label">Chargeback ID</p><p class="font-mono text-sm">CB-${cb.id}</p></div>
                <div><p class="field-label">Status</p><p><span class="${statusBadgeClass(cb.status)}">${escapeHtml(cb.status)}</span></p></div>
                <div><p class="field-label">Merchant</p><p>${escapeHtml(cb.user_name)}<span class="block text-sm text-text-secondary">${escapeHtml(cb.user_email)}</span></p></div>
                <div><p class="field-label">Original PayIn</p><p class="font-mono text-sm">${escapeHtml(cb.transaction_reference)}</p></div>
                <div><p class="field-label">Gateway / Provider</p><p>${escapeHtml(cb.gateway_name || '—')} <span class="text-text-secondary">(${escapeHtml(cb.provider)})</span></p></div>
                <div><p class="field-label">Provider dispute ID</p><p class="font-mono text-sm">${escapeHtml(cb.gateway_chargeback_id)}</p></div>
                <div><p class="field-label">Amount</p><p>${money(cb.amount, cb.currency)}</p></div>
                <div><p class="field-label">Fee</p><p>${money(cb.fee, cb.currency)}</p></div>
                <div><p class="field-label">Total impact</p><p class="font-semibold">${money(cb.total_amount, cb.currency)}</p></div>
                <div><p class="field-label">Reason</p><p>${escapeHtml(cb.reason || '—')} <span class="text-text-secondary">${escapeHtml(cb.reason_code || '')}</span></p></div>
                <div><p class="field-label">Provider status (raw)</p><p class="text-text-secondary">${escapeHtml(cb.provider_status || '—')}</p></div>
                <div><p class="field-label">Financial impact applied</p><p>${cb.financial_impact_applied_at ? formatIST(cb.financial_impact_applied_at) : 'Not yet'}</p></div>
                <div><p class="field-label">Due date</p><p>${cb.due_at ? formatIST(cb.due_at) : '—'}</p></div>
                <div><p class="field-label">Resolved</p><p>${cb.resolved_at ? formatIST(cb.resolved_at) : '—'}</p></div>
                <div><p class="field-label">Resolution</p><p>${escapeHtml(cb.resolution || '—')}</p></div>
            </div>

            <div>
                <p class="field-label mb-2">Event timeline</p>
                <ul class="space-y-2">
                    ${data.timeline.map((ev) => `
                        <li class="flex items-center gap-2 text-sm flex-wrap">
                            <span class="${statusBadgeClass(ev.status)}">${escapeHtml(ev.status)}</span>
                            <span class="text-text-secondary">${ev.occurred_at ? formatIST(ev.occurred_at) : '—'}</span>
                            <span class="font-mono text-xs text-text-secondary">${escapeHtml(ev.event_type || '')}</span>
                            ${!ev.applied ? `<span class="badge-neutral">skipped${ev.skip_reason ? ': ' + escapeHtml(ev.skip_reason) : ''}</span>` : ''}
                        </li>
                    `).join('') || '<li class="text-text-secondary text-sm">No events recorded yet.</li>'}
                </ul>
            </div>

            <div>
                <p class="field-label mb-2">Ledger impact</p>
                ${data.ledger.length ? `
                    <div class="overflow-x-auto">
                        <table class="table-base">
                            <thead><tr><th>Type</th><th class="text-right">Amount</th><th class="text-right">Available after</th><th class="text-right">Receivable after</th><th>Date</th></tr></thead>
                            <tbody>
                                ${data.ledger.map((l) => `
                                    <tr>
                                        <td class="text-sm">${escapeHtml(l.entry_type)}</td>
                                        <td class="table-amount ${l.amount < 0 ? 'text-danger' : 'text-success'}">${money(l.amount, cb.currency)}</td>
                                        <td class="table-amount">${money(l.available_balance_after, cb.currency)}</td>
                                        <td class="table-amount">${money(l.receivable_balance_after, cb.currency)}</td>
                                        <td class="text-text-secondary whitespace-nowrap">${formatIST(l.created_at)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                ` : '<p class="text-text-secondary text-sm">No financial impact applied yet.</p>'}
            </div>
        `;
    }

    form.addEventListener('input', (e) => {
        if (e.target.id === 'f-search') {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => load(1), 350);
        }
    });
    form.addEventListener('change', (e) => {
        if (e.target.id !== 'f-search') load(1);
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); load(1); });

    async function loadCustomerFilter() {
        const select = document.getElementById('f-customer');
        if (!select) return;
        const { success, data } = await apiFetch('/api/admin/users/customers-lite.php');
        if (!success) return;
        select.insertAdjacentHTML('beforeend', data.customers.map((c) =>
            `<option value="${c.id}">${escapeHtml(c.name)} (${escapeHtml(c.email)})</option>`
        ).join(''));
    }

    loadCustomerFilter();
    load(1);
})();
