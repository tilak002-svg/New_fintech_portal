(function () {
    'use strict';
    const { apiFetch, escapeHtml, formatMoney: money } = window.Verapay;

    const isOperator = document.querySelector('#txn-table thead th')?.textContent.trim() === 'Customer';
    const form = document.getElementById('filters-form');
    const tbody = document.getElementById('txn-tbody');
    const pagination = document.getElementById('txn-pagination');
    const colCount = isOperator ? 8 : 7;

    let currentPage = 1;
    let searchDebounce;

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

    function buildQuery(page) {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((k) => { if (!params.get(k)) params.delete(k); });
        params.set('page', page);
        params.set('per_page', 15);
        return params.toString();
    }

    async function load(page = 1) {
        currentPage = page;
        tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-8 text-text-secondary">Loading transactions…</td></tr>`;
        const { success, data, message } = await apiFetch('/api/transactions/list.php?' + buildQuery(page));

        if (!success) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                    <p class="empty-state-title">We couldn't load transactions.</p>
                    <p class="empty-state-body">${escapeHtml(message || 'Please try again.')}</p>
                    <button class="btn-secondary" id="txn-retry">Try again</button>
                </div>
            </td></tr>`;
            document.getElementById('txn-retry')?.addEventListener('click', () => load(page));
            pagination.innerHTML = '';
            return;
        }

        if (!data.transactions.length) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10.5" cy="10.5" r="6.5"/><path d="M20 20l-4.5-4.5"/></svg></span>
                    <p class="empty-state-title">No transactions found</p>
                    <p class="empty-state-body">Try adjusting your filters or search terms.</p>
                </div>
            </td></tr>`;
            pagination.innerHTML = '';
            return;
        }

        tbody.innerHTML = data.transactions.map((t) => `
            <tr class="border-l-4 ${statusBorderClass(t.status)}">
                ${isOperator ? `<td>
                    <span class="block text-md text-text-primary">${escapeHtml(t.user_name)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(t.user_email)}</span>
                </td>` : ''}
                <td>
                    <span class="inline-flex items-center gap-2.5">
                        ${typeIconChip(t.type)}
                        <span class="font-mono text-sm">${escapeHtml(t.reference)}</span>
                    </span>
                </td>
                <td class="capitalize">${escapeHtml(t.type)}</td>
                <td>${escapeHtml(t.method)}</td>
                <td class="table-amount">${money(t.amount)}</td>
                <td><span class="${statusBadgeClass(t.status)}">${escapeHtml(t.status)}</span></td>
                <td class="text-text-secondary whitespace-nowrap">${new Date(t.created_at).toLocaleString()}</td>
                <td><button type="button" class="btn-icon" data-view-transaction="${t.id}" aria-label="View transaction details"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.75"/></svg></button></td>
            </tr>`).join('');

        const { page: p, total_pages, total } = data.pagination;
        pagination.innerHTML = `
            <span class="text-sm text-text-secondary">Showing page ${p} of ${total_pages} (${total} total)</span>
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary !px-4 !py-2" id="txn-prev" ${p <= 1 ? 'disabled' : ''}>Previous</button>
                <button type="button" class="btn-secondary !px-4 !py-2" id="txn-next" ${p >= total_pages ? 'disabled' : ''}>Next</button>
            </div>`;
        document.getElementById('txn-prev')?.addEventListener('click', () => load(p - 1));
        document.getElementById('txn-next')?.addEventListener('click', () => load(p + 1));
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
