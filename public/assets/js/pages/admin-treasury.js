(function () {
    'use strict';
    const { apiFetch, escapeHtml, formatMoney: money } = window.Verapay;

    const form = document.getElementById('treasury-filters');
    const perPageSelect = document.getElementById('treasury-per-page');
    const tbody = document.getElementById('treasury-tbody');
    const pagination = document.getElementById('treasury-pagination');
    const downloadLink = document.getElementById('treasury-download');
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

    function typeIconChip(type) {
        const tone = type === 'deposit' ? 'icon-chip-success' : 'icon-chip-warning';
        const path = type === 'deposit'
            ? '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8.5 11.5 12 15l3.5-3.5"/>'
            : '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 12.5 12 9l3.5 3.5"/>';
        return `<span class="icon-chip-sm ${tone}"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${path}</svg></span>`;
    }

    function buildParams(page) {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((k) => { if (!params.get(k)) params.delete(k); });
        params.set('page', page);
        params.set('per_page', perPageSelect.value);
        return params;
    }

    function updateDownloadLink() {
        const params = buildParams(1);
        params.delete('page');
        params.delete('per_page');
        downloadLink.href = '/api/admin/treasury/export.php?' + params.toString();
    }

    async function load(page = 1) {
        updateDownloadLink();
        tbody.innerHTML = `<tr><td colspan="${colCount}" class="text-center py-8 text-text-secondary">Loading ledger…</td></tr>`;
        const { success, data, message } = await apiFetch('/api/admin/treasury/list.php?' + buildParams(page).toString());

        if (!success) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                    <p class="empty-state-title">We couldn't load the ledger.</p>
                    <p class="empty-state-body">${escapeHtml(message || 'Please try again.')}</p>
                    <button class="btn-secondary" id="treasury-retry">Try again</button>
                </div>
            </td></tr>`;
            document.getElementById('treasury-retry')?.addEventListener('click', () => load(page));
            pagination.innerHTML = '';
            return;
        }

        if (!data.entries.length) {
            tbody.innerHTML = `<tr><td colspan="${colCount}">
                <div class="empty-state">
                    <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h4l2 3h4l2-3h4"/><path d="M5.5 5h13L21 12v6a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18v-6Z"/></svg></span>
                    <p class="empty-state-title">No ledger entries found</p>
                    <p class="empty-state-body">Try adjusting your filters or search terms.</p>
                </div>
            </td></tr>`;
            pagination.innerHTML = '';
            return;
        }

        tbody.innerHTML = data.entries.map((row) => `
            <tr class="border-l-4 ${statusBorderClass(row.status)}">
                <td class="text-text-secondary whitespace-nowrap">${new Date(row.created_at).toLocaleString()}</td>
                <td>
                    <span class="block text-md text-text-primary">${escapeHtml(row.merchant_name)}</span>
                    <span class="block text-sm text-text-secondary">${escapeHtml(row.merchant_email)}</span>
                </td>
                <td>
                    <span class="inline-flex items-center gap-2">
                        ${typeIconChip(row.type)}
                        <span class="capitalize">${escapeHtml(row.type)}</span>
                    </span>
                </td>
                <td class="font-mono text-sm">${escapeHtml(row.reference)}</td>
                <td class="table-amount text-success">${row.type === 'deposit' ? money(row.amount) : '—'}</td>
                <td class="table-amount text-danger">${row.type === 'withdrawal' ? money(row.amount) : '—'}</td>
                <td class="table-amount font-medium">${money(row.running_balance)}</td>
                <td><span class="${statusBadgeClass(row.status)}">${escapeHtml(row.status)}</span></td>
            </tr>`).join('');

        const { page: p, total_pages, total } = data.pagination;
        pagination.innerHTML = `
            <span class="text-sm text-text-secondary">Showing page ${p} of ${total_pages} (${total} total)</span>
            <div class="flex items-center gap-2">
                <button type="button" class="btn-secondary !px-4 !py-2" id="treasury-prev" ${p <= 1 ? 'disabled' : ''}>Previous</button>
                <button type="button" class="btn-secondary !px-4 !py-2" id="treasury-next" ${p >= total_pages ? 'disabled' : ''}>Next</button>
            </div>`;
        document.getElementById('treasury-prev')?.addEventListener('click', () => load(p - 1));
        document.getElementById('treasury-next')?.addEventListener('click', () => load(p + 1));
    }

    form.addEventListener('input', (e) => {
        if (e.target.id === 'tf-search') {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => load(1), 350);
        }
    });
    form.addEventListener('change', (e) => {
        if (e.target.id !== 'tf-search') load(1);
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); load(1); });
    perPageSelect.addEventListener('change', () => load(1));

    load(1);
})();
