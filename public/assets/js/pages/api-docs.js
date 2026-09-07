(function () {
    'use strict';
    const { apiFetch, escapeHtml, setButtonLoading } = window.Verapay;

    document.querySelectorAll('.doc-endpoint[data-try]').forEach((details) => {
        let config;
        try {
            config = JSON.parse(details.dataset.try || '{}');
        } catch (e) {
            return;
        }
        if (!config.url) return;

        const fieldsContainer = details.querySelector('.try-it-fields');
        const sendBtn = details.querySelector('.try-it-send');
        const resultBox = details.querySelector('.try-it-result');
        const statusEl = details.querySelector('.try-it-status');
        const elapsedEl = details.querySelector('.try-it-elapsed');
        const responseEl = details.querySelector('.try-it-response');

        const fields = config.fields || [];
        fieldsContainer.innerHTML = fields.map((f, i) => `
            <div>
                <label for="try-${details.id}-${i}" class="field-label">${escapeHtml(f.label)}</label>
                <input type="text" id="try-${details.id}-${i}" class="field-input" value="${escapeHtml(f.default || '')}" data-field-name="${escapeHtml(f.name)}">
            </div>`).join('');

        if (!fields.length) {
            fieldsContainer.classList.add('hidden');
        }

        sendBtn.addEventListener('click', async () => {
            setButtonLoading(sendBtn, true);
            resultBox.classList.add('hidden');

            const values = {};
            fieldsContainer.querySelectorAll('[data-field-name]').forEach((input) => {
                if (input.value.trim() !== '') {
                    values[input.dataset.fieldName] = input.value.trim();
                }
            });

            let url = config.url;
            let fetchOpts = {};
            let requestDisplay;

            if (config.query) {
                const params = new URLSearchParams(values);
                url += params.toString() ? '?' + params.toString() : '';
                requestDisplay = `GET ${url}`;
            } else {
                fetchOpts = { method: 'POST', body: values };
                requestDisplay = `POST ${url}\n\n${JSON.stringify(values, null, 2)}`;
            }

            const started = performance.now();
            let result;
            try {
                result = await apiFetch(url, fetchOpts);
            } catch (e) {
                result = { status: 0, success: false, data: null, message: 'Network error.' };
            }
            const elapsedMs = Math.round(performance.now() - started);

            setButtonLoading(sendBtn, false);
            resultBox.classList.remove('hidden');

            statusEl.textContent = `HTTP ${result.status}`;
            statusEl.className = 'try-it-status font-mono ' + (result.success ? 'text-success' : 'text-danger');
            elapsedEl.textContent = `${elapsedMs} ms`;

            const responseBody = JSON.stringify({ success: result.success, data: result.data, message: result.message }, null, 2);
            responseEl.innerHTML = `
                <p class="text-sm font-semibold text-text-primary mb-1.5">Request</p>
                <div class="code-block"><pre><code>${escapeHtml(requestDisplay)}</code></pre></div>
                <p class="text-sm font-semibold text-text-primary mb-1.5 mt-3">Response</p>
                <div class="code-block"><pre><code>${escapeHtml(responseBody)}</code></pre></div>`;
        });
    });
})();
