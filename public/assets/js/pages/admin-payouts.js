(function () {
    'use strict';
    const { apiFetch, escapeHtml, formatMoney: money, formatIST } = window.Verapay;

    const form = document.getElementById('filters-form');
    const tbody = document.getElementById('payout-tbody');
    const pagination = document.getElementById('payout-pagination');
    const colCount = 8;
    let searchDebounce;

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
        params.set('type', 'withdrawal');
        params.set('source', 'api');
        params.set('page', page);
        params.set('per_page', 15);
        return params.toString();
    }

    async function load(page = 1) {
        tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-8 text-text-secondary">Loading PayOuts…</td></tr>`;
        const { success, data, message } = await apiFetch('/api/transactions/list.php?' + buildQuery(page));

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
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.5-4.5"/></svg></span>
                    <p class="empty-state-title">No PayOuts found</p>
                    <p class="empty-state-body">Try adjusting your filters, or no merchant has integrated the PayOut API yet.</p>
                </div>
            </td></tr>`;
            pagination.innerHTML = '';
            return;
        }

        tbody.innerHTML = data.transactions.map((t) => `
            <tr class="border-l-4 ${statusBorderClass(t.status)}">
                <td>
                    <span class="block text-md text-text-primary">${escapeHtml(t.user_name)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(t.user_email)}</span>
                </td>
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
