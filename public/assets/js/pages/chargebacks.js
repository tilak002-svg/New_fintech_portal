(function () {
    'use strict';
    const { apiFetch, escapeHtml, formatMoney: money, openModal, closeModal } = window.Verapay;

    const form = document.getElementById('filters-form');
    const tbody = document.getElementById('cb-tbody');
    const pagination = document.getElementById('cb-pagination');
    const colCount = 7;
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
        const { success, data, message } = await apiFetch('/api/chargebacks/list.php?' + buildQuery(page));

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
                    <p class="empty-state-title">No chargebacks</p>
                    <p class="empty-state-body">None of your PayIns have been disputed.</p>
                </div>
            </td></tr>`;
            pagination.innerHTML = '';
            return;
        }

        tbody.innerHTML = data.chargebacks.map((cb) => `
            <tr class="border-l-4 ${statusBorderClass(cb.status)}">
                <td><span class="font-mono text-sm">CB-${cb.id}</span></td>
                <td>
                    <span class="block font-mono text-sm">${escapeHtml(cb.transaction_reference)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(cb.merchant_order_id || '—')}</span>
                </td>
                <td class="table-amount">${money(cb.amount, cb.currency)}</td>
                <td class="table-amount">${money(cb.fee, cb.currency)}</td>
                <td><span class="${statusBadgeClass(cb.status)}">${escapeHtml(cb.status)}</span></td>
                <td class="text-text-secondary whitespace-nowrap">${new Date(cb.created_at).toLocaleString()}</td>
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
            <div class="grid grid-cols-2 gap-4">
                <div><p class="field-label">Chargeback ID</p><p class="font-mono text-sm">CB-${cb.id}</p></div>
                <div><p class="field-label">Status</p><p><span class="${statusBadgeClass(cb.status)}">${escapeHtml(cb.status)}</span></p></div>
                <div><p class="field-label">Original PayIn</p><p class="font-mono text-sm">${escapeHtml(cb.transaction_reference)}</p></div>
                <div><p class="field-label">Merchant order ID</p><p class="font-mono text-sm">${escapeHtml(cb.merchant_order_id || '—')}</p></div>
                <div><p class="field-label">Amount</p><p>${money(cb.amount, cb.currency)}</p></div>
                <div><p class="field-label">Fee</p><p>${money(cb.fee, cb.currency)}</p></div>
                <div><p class="field-label">Total impact</p><p class="font-semibold">${money(cb.total_amount, cb.currency)}</p></div>
                <div><p class="field-label">Gateway</p><p>${escapeHtml(cb.gateway_name || '—')}</p></div>
                <div><p class="field-label">Reason</p><p>${escapeHtml(cb.reason || '—')}</p></div>
                <div><p class="field-label">Due date</p><p>${cb.due_at ? new Date(cb.due_at).toLocaleString() : '—'}</p></div>
                <div><p class="field-label">Resolved</p><p>${cb.resolved_at ? new Date(cb.resolved_at).toLocaleString() : '—'}</p></div>
                <div><p class="field-label">Resolution</p><p>${escapeHtml(cb.resolution || '—')}</p></div>
            </div>
            <div>
                <p class="field-label mb-2">Timeline</p>
                <ul class="space-y-2">
                    ${data.timeline.map((ev) => `
                        <li class="flex items-center gap-2 text-sm">
                            <span class="${statusBadgeClass(ev.status)}">${escapeHtml(ev.status)}</span>
                            <span class="text-text-secondary">${ev.occurred_at ? new Date(ev.occurred_at).toLocaleString() : '—'}</span>
                            ${!ev.applied ? '<span class="text-text-secondary italic">(not applied)</span>' : ''}
                        </li>
                    `).join('') || '<li class="text-text-secondary text-sm">No events recorded yet.</li>'}
                </ul>
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

    load(1);
})();
