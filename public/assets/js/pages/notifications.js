(function () {
    'use strict';
    const { apiFetch, showToast, escapeHtml } = window.Verapay;

    const iconSvg = {
        deposit: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8.5 11.5 12 15l3.5-3.5"/>',
        withdrawal: '<circle cx="12" cy="12" r="9"/><path d="M12 16V8M8.5 12.5 12 9l3.5 3.5"/>',
        support: '<path d="M4 5.5h16v10H9.5L5 19v-3.5H4Z"/><path d="M8 9.5h8M8 12.5h5"/>',
        security: '<path d="M12 3.5 19.5 6.5V11c0 5-3.2 8.2-7.5 9.5C7.7 19.2 4.5 16 4.5 11V6.5L12 3.5Z"/>',
        // Admin/operator-only: gateway health, misconfiguration, and
        // limit-reached alerts fanned out by notify_admins() (see
        // includes/functions.php) — reuses icons.php's 'gateway' path.
        gateway: '<rect x="3" y="9" width="7" height="7" rx="1.5"/><rect x="14" y="9" width="7" height="7" rx="1.5"/><path d="M10 12.5h4"/><path d="M6.5 9V6a1.5 1.5 0 0 1 1.5-1.5h8A1.5 1.5 0 0 1 17.5 6v3"/>',
    };
    const toneFor = {
        deposit: 'icon-chip-success',
        withdrawal: 'icon-chip-warning',
        support: 'icon-chip-info',
        security: 'icon-chip-brand',
        gateway: 'icon-chip-warning',
    };
    function iconFor(type) {
        const path = iconSvg[type] || '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/>';
        const tone = toneFor[type] || 'icon-chip-neutral';
        return `<span class="icon-chip-sm ${tone}"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">${path}</svg></span>`;
    }

    function timeLabel(iso) {
        return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function dateBucket(iso) {
        const d = new Date(iso);
        const now = new Date();
        const startOfDay = (date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
        const days = Math.round((startOfDay(now) - startOfDay(d)) / 86400000);
        if (days <= 0) return 'Today';
        if (days === 1) return 'Yesterday';
        if (days <= 7) return 'This week';
        return 'Earlier';
    }

    function notificationRow(n) {
        return `
            <li class="flex items-start gap-3 px-5 py-4 transition-colors duration-instant hover:bg-surface-muted ${n.is_read ? '' : 'bg-brand-muted/30'}" data-id="${n.id}">
                ${iconFor(n.type)}
                <span class="min-w-0 flex-1">
                    <span class="block text-md font-semibold text-text-primary">${escapeHtml(n.title)}</span>
                    <span class="block text-sm text-text-secondary mt-0.5">${escapeHtml(n.message)}</span>
                    <span class="block text-xs text-text-secondary mt-1">${timeLabel(n.created_at)}</span>
                </span>
                ${!n.is_read ? `<button type="button" class="mark-read-btn text-sm text-brand font-medium hover:underline shrink-0" data-id="${n.id}">Mark as read</button>` : ''}
            </li>`;
    }

    async function load() {
        const list = document.getElementById('notifications-list');
        const { success, data, message } = await apiFetch('/api/notifications/list.php?limit=50');
        if (!success) {
            list.innerHTML = `<li class="empty-state">
                <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none"/></svg></span>
                <p class="empty-state-title">We couldn't load your notifications.</p>
                <p class="empty-state-body !mb-0">${escapeHtml(message || 'Please try again.')}</p>
            </li>`;
            return;
        }
        if (!data.notifications.length) {
            list.innerHTML = `<li class="empty-state">
                <span class="empty-state-icon"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 1 1 12 0c0 4 1.5 5.5 1.5 5.5H4.5S6 13 6 9Z"/><path d="M9.5 17.5a2.5 2.5 0 0 0 5 0"/></svg></span>
                <p class="empty-state-title">No notifications yet</p>
                <p class="empty-state-body !mb-0">Payment, support, and security updates will appear here.</p>
            </li>`;
            return;
        }

        let lastBucket = null;
        list.innerHTML = data.notifications.map((n) => {
            const bucket = dateBucket(n.created_at);
            const heading = bucket !== lastBucket ? `<li class="list-date-heading">${bucket}</li>` : '';
            lastBucket = bucket;
            return heading + notificationRow(n);
        }).join('');

        list.querySelectorAll('.mark-read-btn').forEach((btn) => btn.addEventListener('click', async () => {
            await apiFetch('/api/notifications/mark-read.php', { method: 'POST', body: { id: btn.dataset.id } });
            load();
        }));
    }

    document.getElementById('mark-all-read').addEventListener('click', async () => {
        const { success } = await apiFetch('/api/notifications/mark-read.php', { method: 'POST', body: { all: true } });
        if (success) load(); else showToast('Unable to update notifications.', 'error');
    });

    load();
})();
