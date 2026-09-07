(function () {
    'use strict';
    const { apiFetch, showToast, setButtonLoading } = window.Verapay;

    document.getElementById('password-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorEl = document.getElementById('password-error');
        errorEl.classList.add('hidden');

        const current = document.getElementById('current-password').value;
        const next = document.getElementById('new-password').value;
        const confirm = document.getElementById('confirm-password').value;

        if (next.length < 10) {
            errorEl.textContent = 'New password must be at least 10 characters.';
            errorEl.classList.remove('hidden');
            return;
        }
        if (next !== confirm) {
            errorEl.textContent = 'New password and confirmation do not match.';
            errorEl.classList.remove('hidden');
            return;
        }

        const submitBtn = document.getElementById('password-submit');
        setButtonLoading(submitBtn, true);
        const { success, message } = await apiFetch('/api/settings/change-password.php', {
            method: 'POST',
            body: { current_password: current, new_password: next, confirm_password: confirm },
        });
        setButtonLoading(submitBtn, false);

        if (!success) {
            errorEl.textContent = message || 'Unable to update your password.';
            errorEl.classList.remove('hidden');
            return;
        }
        showToast('Password updated.', 'success');
        e.target.reset();
    });
})();
