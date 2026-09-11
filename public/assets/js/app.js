(function () {
    'use strict';

    window.Verapay = window.Verapay || {};

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    const CURRENCY_SYMBOLS = { INR: '₹', USD: '$', EUR: '€', GBP: '£' };

    /** Formats a decimal amount with its currency symbol. Defaults to INR. */
    function formatMoney(amount, currency = 'INR') {
        const symbol = CURRENCY_SYMBOLS[currency] || (currency ? currency + ' ' : '₹');
        return symbol + Number(amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    window.Verapay.formatMoney = formatMoney;

    /**
     * Every timestamp the API returns is UTC, formatted as MySQL's plain
     * "YYYY-MM-DD HH:MM:SS" (no timezone marker). A bare `new Date(value)`
     * on that string is silently mis-parsed by JS as already being in the
     * VIEWER'S OWN local time — so `.toLocaleString()` on it just echoes
     * the UTC clock digits back, understating the real local time by
     * whatever the viewer's UTC offset is (e.g. showing 11:45 AM for a
     * payment that actually happened at 5:15 PM IST). This makes a
     * MySQL-shaped string explicitly UTC before handing it to Date, so
     * every .toLocaleString()/.toLocaleDateString() call downstream
     * converts correctly. Pass-through for values that already carry an
     * explicit offset (Z, +00:00, etc.) or are already a Date.
     */
    function toLocalDate(value) {
        if (!value) return null;
        if (value instanceof Date) return value;
        const hasOffset = /Z$|[+-]\d{2}:?\d{2}$/.test(value);
        return new Date(hasOffset ? value : value.replace(' ', 'T') + 'Z');
    }
    window.Verapay.toLocalDate = toLocalDate;

    /**
     * Formats a server timestamp as India time (Asia/Kolkata, UTC+5:30),
     * explicitly — not whatever timezone the viewer's own device happens to
     * be set to. This platform operates in India, so every viewer should
     * read the same IST clock time for a given event. extraOptions merges
     * over the IST-forcing base (e.g. { month: 'short', day: 'numeric' }
     * for a shorter format than the full date+time default).
     */
    function formatIST(value, extraOptions = {}) {
        const date = toLocalDate(value);
        if (!date) return '';
        return date.toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', ...extraOptions });
    }
    window.Verapay.formatIST = formatIST;

    /** Date-only India-time formatting (no time-of-day component). */
    function formatISTDate(value) {
        const date = toLocalDate(value);
        if (!date) return '';
        return date.toLocaleDateString('en-IN', { timeZone: 'Asia/Kolkata' });
    }
    window.Verapay.formatISTDate = formatISTDate;

    /** Wrapper around fetch() that always sends the CSRF header and parses JSON. */
    async function apiFetch(url, options = {}) {
        const opts = Object.assign({ headers: {} }, options);
        opts.headers = Object.assign({ 'X-CSRF-Token': csrfToken() }, opts.headers);
        if (opts.body && !(opts.body instanceof FormData) && typeof opts.body !== 'string') {
            opts.body = JSON.stringify(opts.body);
            opts.headers['Content-Type'] = 'application/json';
        }
        const res = await fetch(url, opts);
        let body;
        try {
            body = await res.json();
        } catch (e) {
            body = { success: false, data: null, message: 'Unexpected server response.' };
        }
        if (res.status === 401) {
            window.location.href = '/login';
        }
        if (res.status === 403 && body.message && body.message.toLowerCase().includes('suspend')) {
            window.location.href = '/suspended';
        }
        return { status: res.status, ...body };
    }
    window.Verapay.apiFetch = apiFetch;

    // ---------------- Toasts ----------------
    const toastIcons = {
        success: '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/></svg>',
        error: '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg>',
        warning: '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg>',
        info: '<svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg>',
    };
    const toastColor = { success: 'text-success', error: 'text-danger', warning: 'text-warning', info: 'text-info' };

    function showToast(message, type = 'info', persistent = false) {
        const region = document.getElementById('toast-region');
        if (!region) return;
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');
        el.innerHTML = `
            <span class="${toastColor[type]}">${toastIcons[type] || toastIcons.info}</span>
            <span class="flex-1 text-md text-text-primary">${escapeHtml(message)}</span>
            <button type="button" class="btn-icon !w-6 !h-6 shrink-0" aria-label="Dismiss notification">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>`;
        el.querySelector('button').addEventListener('click', () => el.remove());
        region.appendChild(el);
        if (!persistent) {
            setTimeout(() => el.remove(), type === 'error' ? 8000 : 5000);
        }
    }
    window.Verapay.showToast = showToast;

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
    window.Verapay.escapeHtml = escapeHtml;

    // ---------------- Expandable nav groups (e.g. Payment gateways) ----------------
    document.querySelectorAll('.nav-group-toggle').forEach((toggle) => {
        const panel = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!panel) return;
        toggle.addEventListener('click', () => {
            const expanded = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!expanded));
            panel.classList.toggle('hidden', expanded);
            toggle.querySelector('.nav-group-chevron')?.classList.toggle('rotate-180', !expanded);
        });
        if (toggle.getAttribute('aria-expanded') === 'true') {
            toggle.querySelector('.nav-group-chevron')?.classList.add('rotate-180');
        }
    });

    // ---------------- Mobile sidebar ----------------
    const sidebar = document.getElementById('app-sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarBackdrop = document.getElementById('sidebar-backdrop');

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('-translate-x-full');
        sidebarBackdrop?.classList.add('hidden');
        sidebarToggle?.setAttribute('aria-expanded', 'false');
    }
    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('-translate-x-full');
        sidebarBackdrop?.classList.remove('hidden');
        sidebarToggle?.setAttribute('aria-expanded', 'true');
    }
    sidebarToggle?.addEventListener('click', () => {
        const isOpen = sidebarToggle.getAttribute('aria-expanded') === 'true';
        isOpen ? closeSidebar() : openSidebar();
    });
    sidebarBackdrop?.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeSidebar();
    });

    // ---------------- Generic dropdown (notifications / profile) ----------------
    function setupDropdown(toggleId, panelId, onOpen) {
        const toggle = document.getElementById(toggleId);
        const panel = document.getElementById(panelId);
        if (!toggle || !panel) return;

        function close() {
            panel.classList.add('hidden');
            toggle.setAttribute('aria-expanded', 'false');
        }
        function open() {
            document.querySelectorAll('[aria-haspopup="true"][aria-expanded="true"]').forEach((el) => {
                if (el !== toggle) el.click();
            });
            panel.classList.remove('hidden');
            toggle.setAttribute('aria-expanded', 'true');
            if (typeof onOpen === 'function') onOpen();
        }
        toggle.addEventListener('click', () => {
            toggle.getAttribute('aria-expanded') === 'true' ? close() : open();
        });
        document.addEventListener('click', (e) => {
            if (!panel.contains(e.target) && !toggle.contains(e.target)) close();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                close();
                toggle.focus();
            }
        });
    }

    let notifPanelLoaded = false;
    setupDropdown('notif-toggle', 'notif-panel', async () => {
        if (notifPanelLoaded) return;
        notifPanelLoaded = true;
        const list = document.getElementById('notif-panel-list');
        const { success, data } = await apiFetch('/api/notifications/list.php?limit=5');
        if (!success || !data?.notifications?.length) {
            list.innerHTML = '<div class="px-4 py-6 text-sm text-text-secondary text-center">No notifications yet.</div>';
            return;
        }
        list.innerHTML = data.notifications.map((n) => `
            <a href="/notifications" class="block px-4 py-3 hover:bg-surface-muted ${n.is_read ? '' : 'bg-brand-muted/40'}">
                <span class="block text-md font-semibold text-text-primary">${escapeHtml(n.title)}</span>
                <span class="block text-sm text-text-secondary mt-0.5">${escapeHtml(n.message)}</span>
            </a>`).join('');
    });
    setupDropdown('profile-toggle', 'profile-panel');

    // ---------------- Modals (<dialog>) ----------------
    document.querySelectorAll('[data-modal-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const dialog = document.getElementById(trigger.getAttribute('data-modal-trigger'));
            if (!dialog) return;
            dialog.showModal();
            dialog._returnFocus = trigger;
            dialog.querySelector('input,button,select,textarea')?.focus();
        });
    });
    document.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('close', () => {
            dialog._returnFocus?.focus();
        });
        dialog.querySelectorAll('[data-modal-close]').forEach((btn) => {
            btn.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) dialog.close();
        });
    });
    window.Verapay.openModal = (id) => document.getElementById(id)?.showModal();
    window.Verapay.closeModal = (id) => document.getElementById(id)?.close();

    // ---------------- Button loading helper ----------------
    window.Verapay.setButtonLoading = (btn, loading) => {
        if (!btn) return;
        btn.disabled = loading;
        btn.classList.toggle('btn-loading', loading);
    };

    // ---------------- Transaction detail modal ----------------
    // Shared by every PayIn/PayOut/Transactions table (customer + admin) —
    // see includes/footer.php for the <dialog> markup and
    // public/api/transactions/detail.php for the data. Rows are added to
    // their tables after an async load, so this listens on the document
    // rather than binding to buttons that don't exist yet at page load.
    const timelineToneDot = { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger', neutral: 'bg-text-secondary' };
    // Sidebar renders the admin/operator nav (including this Customers link)
    // only for staff — used to hide the "Merchant" section when a customer
    // is looking at their own transaction, where it's redundant.
    const isStaffViewer = !!document.querySelector('a[href="/admin/users"]');

    function tdRow(label, value) {
        if (value === null || value === undefined || value === '') return '';
        return `<div class="flex items-start justify-between gap-4 py-1.5">
            <dt class="text-sm text-text-secondary shrink-0">${escapeHtml(label)}</dt>
            <dd class="text-sm text-text-primary text-right font-medium">${value}</dd>
        </div>`;
    }

    function tdSection(title, rowsHtml) {
        const rows = rowsHtml.filter(Boolean).join('');
        if (!rows) return '';
        return `<div>
            <h3 class="text-sm font-semibold text-text-primary mb-1.5">${escapeHtml(title)}</h3>
            <dl class="divide-y divide-border">${rows}</dl>
        </div>`;
    }

    async function openTransactionDetail(id) {
        const dialog = document.getElementById('transaction-detail-modal');
        const body = document.getElementById('td-body');
        const subtitle = document.getElementById('td-subtitle');
        if (!dialog || !body) return;

        body.innerHTML = '<p class="text-center py-8 text-text-secondary">Loading…</p>';
        subtitle.textContent = '';
        dialog.showModal();

        const { success, data, message } = await apiFetch('/api/transactions/detail.php?id=' + encodeURIComponent(id));
        if (!success || !data) {
            body.innerHTML = `<div class="empty-state"><p class="empty-state-title">Couldn't load this transaction.</p><p class="empty-state-body">${escapeHtml(message || 'Please try again.')}</p></div>`;
            return;
        }

        const t = data.transaction;
        const statusMap = { success: 'badge-success', pending: 'badge-warning', failed: 'badge-danger', cancelled: 'badge-neutral', refunded: 'badge-info' };
        subtitle.textContent = `${t.type === 'payin' ? 'PayIn' : 'PayOut'} · ${t.reference}`;

        const header = `
            <div class="rounded-md border border-border bg-surface-muted px-4 py-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div><p class="text-xs text-text-secondary mb-0.5">Reference</p><p class="font-mono text-sm text-text-primary">${escapeHtml(t.reference)}</p></div>
                <div><p class="text-xs text-text-secondary mb-0.5">Order ID</p><p class="font-mono text-sm text-text-primary">${escapeHtml(t.merchant_order_id || '—')}</p></div>
                <div><p class="text-xs text-text-secondary mb-0.5">Status</p><span class="${statusMap[t.status] || 'badge-neutral'}">${escapeHtml(t.status)}</span></div>
                <div><p class="text-xs text-text-secondary mb-0.5">Amount</p><p class="text-sm font-semibold text-text-primary">${formatMoney(t.amount, t.currency)}</p></div>
            </div>`;

        const paymentInfo = tdSection('Payment information', [
            tdRow('Type', escapeHtml(t.type === 'payin' ? 'PayIn' : 'PayOut')),
            tdRow('Fee', formatMoney(t.fee, t.currency)),
            tdRow('Net amount', formatMoney(t.net_amount, t.currency)),
            tdRow('Currency', escapeHtml(t.currency)),
            tdRow('Created', formatIST(t.created_at)),
            tdRow('Updated', formatIST(t.updated_at)),
        ]);

        const gatewayInfo = tdSection('Gateway information', [
            tdRow('Gateway', t.gateway_name ? escapeHtml(t.gateway_name) : 'Not yet assigned'),
            tdRow('Provider', t.gateway_provider ? escapeHtml(t.gateway_provider) : ''),
            tdRow('Mode', t.gateway_name ? `<span class="badge-neutral">${t.gateway_sandbox_mode == 1 ? 'Sandbox' : 'Live'}</span>` : ''),
            tdRow('Gateway transaction ID', t.gateway_txn_id ? `<span class="font-mono">${escapeHtml(t.gateway_txn_id)}</span>` : ''),
            tdRow('Checkout session', t.session_status ? `${escapeHtml(t.session_status)}${t.session_expires_at ? ' · expires ' + formatIST(t.session_expires_at) : ''}` : ''),
        ]);

        const merchantInfo = (isStaffViewer && t.user_name) ? tdSection('Merchant', [
            tdRow('Name', escapeHtml(t.user_name)),
            tdRow('Email', escapeHtml(t.user_email)),
        ]) : '';

        const partyInfo = t.type === 'payin'
            ? tdSection('Customer information', [
                tdRow('Name', escapeHtml(t.end_customer_name || '—')),
                tdRow('Email', escapeHtml(t.end_customer_email || '—')),
                tdRow('Phone', escapeHtml(t.end_customer_phone || '—')),
            ])
            : tdSection('Beneficiary information', [
                tdRow('Name', escapeHtml(t.beneficiary_name || '—')),
                tdRow('Bank', escapeHtml(t.beneficiary_bank_name || '—')),
                tdRow('Account (last 4)', t.beneficiary_account_last4 ? `••${escapeHtml(t.beneficiary_account_last4)}` : '—'),
                tdRow('IFSC', escapeHtml(t.beneficiary_ifsc || '—')),
                tdRow('Phone', escapeHtml(t.beneficiary_phone || '—')),
            ]);

        const cb = data.callback || { url: null, status: 'not_configured', attempts: 0, last_attempt_at: null, failure_reason: null };
        const callbackStatusBadge = {
            not_configured: '<span class="badge-neutral">Not configured</span>',
            not_applicable: '<span class="badge-neutral">Awaiting final status</span>',
            pending: '<span class="badge-warning">Not yet sent</span>',
            delivered: '<span class="badge-success">Delivered</span>',
            failed: '<span class="badge-danger">Delivery failed</span>',
        }[cb.status] || '<span class="badge-neutral">—</span>';
        const callbackInfo = tdSection('Callback', [
            tdRow('Callback URL', cb.url ? `<span class="font-mono text-xs break-all">${escapeHtml(cb.url)}</span>` : 'Not configured'),
            tdRow('Delivery status', callbackStatusBadge),
            cb.attempts ? tdRow('Attempts', String(cb.attempts)) : '',
            cb.last_attempt_at ? tdRow('Last attempt', formatIST(cb.last_attempt_at)) : '',
            cb.failure_reason ? tdRow('Failure reason', escapeHtml(cb.failure_reason)) : '',
        ]);

        const timelineHtml = data.timeline.length ? `
            <div>
                <h3 class="text-sm font-semibold text-text-primary mb-2">Timeline</h3>
                <ol class="space-y-3">
                    ${data.timeline.map((ev) => `
                        <li class="flex gap-3">
                            <span class="w-2 h-2 rounded-full mt-1.5 shrink-0 ${timelineToneDot[ev.tone] || timelineToneDot.neutral}"></span>
                            <span class="flex-1">
                                <span class="block text-sm text-text-primary">${escapeHtml(ev.label)}</span>
                                <span class="block text-xs text-text-secondary mt-0.5">${formatIST(ev.occurred_at)}</span>
                            </span>
                        </li>`).join('')}
                </ol>
            </div>` : '';

        body.innerHTML = [header, merchantInfo, paymentInfo, gatewayInfo, partyInfo, callbackInfo, timelineHtml].filter(Boolean).join('<div class="border-t border-border"></div>');
    }
    window.Verapay.openTransactionDetail = openTransactionDetail;

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-view-transaction]');
        if (btn) openTransactionDetail(btn.getAttribute('data-view-transaction'));
    });
})();
