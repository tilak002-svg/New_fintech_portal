(function () {
    'use strict';
    const { apiFetch, escapeHtml, formatMoney: money, formatIST } = window.Verapay;

    const form = document.getElementById('filters-form');
    const tbody = document.getElementById('payout-tbody');
    const pagination = document.getElementById('payout-pagination');
    const colCount = 7;

    function statusBadgeClass(status) {
        const map = { success: 'badge-success', pending: 'badge-warning', failed: 'badge-danger', cancelled: 'badge-neutral', refunded: 'badge-info' };
        return map[status] || 'badge-neutral';
    }

    function statusBorderClass(status) {
        const map = { success: 'border-l-success', pending: 'border-l-warning', failed: 'border-l-danger', cancelled: 'border-l-neutral', refunded: 'border-l-info' };
        return map[status] || 'border-l-neutral';
    }

    function buildQuery(page) {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((k) => { if (!params.get(k)) params.delete(k); });
        params.set('type', 'payout');
        params.set('page', page);
        params.set('per_page', 15);
        return params.toString();
    }

    async function load(page = 1) {
        tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-8 text-text-secondary">Loading PayOuts…</td></tr>`;
        const { success, data, message } = await apiFetch('/api/v1/transactions/list.php?' + buildQuery(page));

        if (!success) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                    <p class="empty-state-title">We couldn't load PayOuts.</p>
                    <p class="empty-state-body">${escapeHtml(message || 'Please try again.')}</p>
                    <button class="btn-secondary" id="payout-retry">Try again</button>
                </div>
            </td></tr>`;
            document.getElementById('payout-retry')?.addEventListener('click', () => load(page));
            pagination.innerHTML = '';
            return;
        }

        if (!data.transactions.length) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 12.5 12 9l3.5 3.5"/></svg></span>
                    <p class="empty-state-title">No PayOuts yet</p>
                    <p class="empty-state-body">Payments your API sends to your customers or vendors will appear here. See the <a href="/api-docs" class="text-brand-emphasis underline">API documentation</a> to get started.</p>
                </div>
            </td></tr>`;
            pagination.innerHTML = '';
            return;
        }

        tbody.innerHTML = data.transactions.map((t) => `
            <tr class="border-l-4 ${statusBorderClass(t.status)}">
                <td><span class="font-mono text-sm">${escapeHtml(t.reference)}</span></td>
                <td><span class="font-mono text-sm text-text-secondary">${escapeHtml(t.merchant_order_id || '—')}</span></td>
                <td>
                    <span class="block text-md text-text-primary">${escapeHtml(t.beneficiary_name || '—')}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(t.beneficiary_bank_name || '')}</span>
                </td>
                <td class="table-amount">
                    ${money(t.amount, t.currency)}
                    ${t.gateway_sandbox_mode == 1 ? '<span class="badge-neutral ml-1.5">Sandbox</span>' : ''}
                </td>
                <td><span class="${statusBadgeClass(t.status)}">${escapeHtml(t.status)}</span></td>
                <td class="text-text-secondary whitespace-nowrap">${formatIST(t.created_at)}</td>
                <td><button type="button" class="btn-icon" data-view-transaction="${t.id}" aria-label="View transaction details"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.75"/></svg></button></td>
            </tr>`).join('');

        const { page: p, total_pages, total } = data.pagination;
        pagination.innerHTML = `
            <span class="text-sm text-text-secondary">Showing page ${p} of ${total_pages} (${total} total)</span>
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary !px-4 !py-2" id="payout-prev" ${p <= 1 ? 'disabled' : ''}>Previous</button>
                <button type="button" class="btn-secondary !px-4 !py-2" id="payout-next" ${p >= total_pages ? 'disabled' : ''}>Next</button>
            </div>`;
        document.getElementById('payout-prev')?.addEventListener('click', () => load(p - 1));
        document.getElementById('payout-next')?.addEventListener('click', () => load(p + 1));
    }

    form.addEventListener('change', () => load(1));
    form.addEventListener('submit', (e) => { e.preventDefault(); load(1); });

    load(1);
})();
