(function () {
    'use strict';
    const { apiFetch, showToast, setButtonLoading } = window.Verapay;

    document.getElementById('retry-webhooks-now')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        setButtonLoading(btn, true);
        const { success, data, message } = await apiFetch('/api/admin/webhooks/retry-now.php', { method: 'POST' });
        setButtonLoading(btn, false);
        if (!success) {
            showToast(message || 'Unable to run retries.', 'error');
            return;
        }
        showToast(`Processed ${data.processed} due deliveries — ${data.delivered} delivered, ${data.failed} failed, ${data.still_pending} still pending.`, 'success');
        setTimeout(() => location.reload(), 1200);
    });

    document.getElementById('run-reconciliation-now')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        setButtonLoading(btn, true);
        const { success, data, message } = await apiFetch('/api/admin/reconciliation/run.php', { method: 'POST' });
        setButtonLoading(btn, false);
        if (!success) {
            showToast(message || 'Unable to run reconciliation.', 'error');
            return;
        }
        showToast(`Checked ${data.checked} pending transactions — ${data.resolved} resolved, ${data.still_pending} still pending.`, 'success');
    });
})();
