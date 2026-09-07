(function () {
    'use strict';
    const { apiFetch, showToast, setButtonLoading } = window.Verapay;

    const badgeClass = { pending: 'badge-warning', verified: 'badge-success', rejected: 'badge-danger' };
    const badgeLabel = { pending: 'Pending review', verified: 'Verified', rejected: 'Rejected' };
    const MAX_BYTES = 5 * 1024 * 1024;
    const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

    function renderDoc(type, doc) {
        const card = document.querySelector(`[data-doc-card="${type}"]`);
        if (!card) return;
        const badge = card.querySelector('[data-doc-badge]');
        const dropzone = card.querySelector('[data-doc-dropzone]');
        const filenameWrap = card.querySelector('[data-doc-filename]');
        const filenameText = card.querySelector('[data-doc-filename-text]');
        const viewLink = card.querySelector('[data-doc-view]');

        if (doc) {
            badge.textContent = badgeLabel[doc.status] || doc.status;
            badge.className = 'badge ' + (badgeClass[doc.status] || 'badge-neutral');
            badge.classList.remove('hidden');
            filenameText.textContent = doc.original_filename;
            viewLink.href = `/api/kyc/download.php?document_type=${encodeURIComponent(type)}`;
            filenameWrap.classList.remove('hidden');
            dropzone.classList.add('hidden');
        } else {
            badge.classList.add('hidden');
            filenameWrap.classList.add('hidden');
            dropzone.classList.remove('hidden');
        }
    }

    function updateProgress() {
        const total = document.querySelectorAll('[data-doc-card]').length;
        const uploaded = document.querySelectorAll('[data-doc-filename]:not(.hidden)').length;
        const countEl = document.getElementById('kyc-progress-count');
        const fillEl = document.getElementById('kyc-progress-fill');
        if (countEl) countEl.textContent = uploaded;
        if (fillEl) fillEl.style.width = `${total ? (uploaded / total) * 100 : 0}%`;
    }

    async function load() {
        const { success, data, message } = await apiFetch('/api/kyc/documents.php');
        if (!success) {
            showToast(message || 'Unable to load your documents.', 'error');
            return;
        }
        Object.entries(data.documents).forEach(([type, doc]) => renderDoc(type, doc));
        updateProgress();
    }

    function validateFile(file, errorEl) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!ALLOWED_EXT.includes(ext)) {
            errorEl.textContent = 'Only PDF, JPG, or PNG files are accepted.';
            errorEl.classList.remove('hidden');
            return false;
        }
        if (file.size > MAX_BYTES) {
            errorEl.textContent = 'File is too large — max 5MB.';
            errorEl.classList.remove('hidden');
            return false;
        }
        return true;
    }

    async function uploadFile(form, file) {
        const type = form.dataset.docType;
        const errorEl = form.querySelector('[data-doc-error]');
        const dropzone = form.querySelector('[data-doc-dropzone]');
        errorEl.classList.add('hidden');

        if (!validateFile(file, errorEl)) return;

        const fd = new FormData();
        fd.append('document_type', type);
        fd.append('document', file);

        dropzone.classList.add('opacity-60', 'pointer-events-none');
        const { success, message } = await apiFetch('/api/kyc/upload.php', { method: 'POST', body: fd });
        dropzone.classList.remove('opacity-60', 'pointer-events-none');

        if (!success) {
            errorEl.textContent = message || 'Upload failed.';
            errorEl.classList.remove('hidden');
            return;
        }

        showToast('Document uploaded.', 'success');
        load();
    }

    document.querySelectorAll('[data-doc-form]').forEach((form) => {
        const input = form.querySelector('[data-doc-input]');
        const dropzone = form.querySelector('[data-doc-dropzone]');
        const replaceBtn = form.querySelector('[data-doc-replace]');

        dropzone.addEventListener('click', () => input.click());
        dropzone.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
        dropzone.setAttribute('tabindex', '0');
        dropzone.setAttribute('role', 'button');

        replaceBtn?.addEventListener('click', () => input.click());

        input.addEventListener('change', () => {
            if (input.files && input.files[0]) uploadFile(form, input.files[0]);
            input.value = '';
        });

        ['dragover', 'dragenter'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.add('border-brand', 'bg-brand-muted/30');
            });
        });
        ['dragleave', 'dragend'].forEach((evt) => {
            dropzone.addEventListener(evt, () => {
                dropzone.classList.remove('border-brand', 'bg-brand-muted/30');
            });
        });
        dropzone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('border-brand', 'bg-brand-muted/30');
            const file = e.dataTransfer?.files?.[0];
            if (file) uploadFile(form, file);
        });

        form.addEventListener('submit', (e) => e.preventDefault());
    });

    load();
})();
