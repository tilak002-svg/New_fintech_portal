<?php

require_once __DIR__ . '/../../includes/modal.php';
require_once __DIR__ . '/../../includes/banner.php';

$extraScripts = [
    '/assets/js/pages/admin-gateways.js'
];

render_hero_banner(
    $user,
    'Payment gateways',
    'Configure the processors Verapay routes payments through. Exactly one gateway is the default at any time — swap it here when a processor changes without any code changes.'
);
?>

<!-- Payment Gateway -->
<div id="payment-gateway-panel">

    <div class="flex justify-end mt-5 mb-6">
        <button type="button"
                class="btn-primary shrink-0"
                data-modal-trigger="add-gateway-modal">
            <?= icon('plus', 'w-4 h-4') ?> Add gateway
        </button>
    </div>

    <div class="card !p-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th scope="col">Gateway</th>
                        <th scope="col">Provider</th>
                        <th scope="col">API key</th>
                        <th scope="col">Priority</th>
                        <th scope="col">Daily limit</th>
                        <th scope="col">Webhook</th>
                        <th scope="col">Status</th>
                        <th scope="col">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody id="gateways-tbody">
                    <tr>
                        <td colspan="8"
                            class="text-center py-8 text-text-secondary">
                            Loading gateways…
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Gateway Modal -->
<dialog id="add-gateway-modal"
        class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-md"
        aria-labelledby="add-gateway-title">

    <form id="add-gateway-form" class="flex flex-col">

        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="add-gateway-title"
                class="text-3xl font-semibold text-text-primary">
                Add payment gateway
            </h2>

            <button type="button"
                    class="btn-icon"
                    data-modal-close
                    aria-label="Close dialog">
                <?= icon('close', 'w-5 h-5') ?>
            </button>
        </div>

        <div class="px-6 py-5 space-y-4">

            <div>
                <label for="ag-name" class="field-label">
                    Display name
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text"
                       id="ag-name"
                       class="field-input"
                       maxlength="80"
                       placeholder="e.g. Backup Processor"
                       required>
            </div>

            <div>
                <label for="ag-provider" class="field-label">
                    Provider
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <select id="ag-provider"
                        class="field-input"
                        required>
                    <option value="">Select a provider…</option>
                    <option value="razorpay">Razorpay</option>
                    <option value="payu">PayU</option>
                    <option value="cashfree">Cashfree</option>
                    <option value="stripe">Stripe</option>
                    <option value="paypal">PayPal</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div id="ag-public-key-field" class="hidden">
                <label for="ag-public-key" class="field-label">
                    <span id="ag-public-key-label">Key ID</span>
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text"
                       id="ag-public-key"
                       class="field-input font-mono"
                       placeholder="rzp_live_…"
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    The public identifier from the same API keys screen as the secret below (Razorpay: Key ID · Cashfree: Client ID). Not sensitive — safe to display in full.
                </p>
            </div>

            <div id="ag-payout-account-field" class="hidden">
                <label for="ag-payout-account" class="field-label">
                    RazorpayX payout account number
                </label>

                <input type="text"
                       id="ag-payout-account"
                       class="field-input font-mono"
                       placeholder="e.g. 7878780080316316"
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    The RazorpayX current account payouts are debited from — required only if this gateway should also process withdrawals, not needed for pay-ins alone. Found on the RazorpayX dashboard, distinct from the beneficiary's own bank account.
                </p>
            </div>

            <div id="ag-sandbox-field" class="hidden">
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" id="ag-sandbox-mode" class="rounded" checked>
                    <span class="text-md text-text-primary">Sandbox / test mode</span>
                </label>
                <p class="field-help">
                    Uncheck only once you have real live-mode credentials — some providers (Cashfree) use a completely separate API endpoint for production.
                </p>
            </div>

            <div>
                <label for="ag-key" class="field-label">
                    API key <span id="ag-key-label-suffix" class="text-text-secondary font-normal"></span>
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text"
                       id="ag-key"
                       class="field-input font-mono"
                       minlength="8"
                       placeholder="sk_live_…"
                       required
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    Encrypted at rest for real outbound use, plus a one-way hash for display — only the last 4 characters are ever shown again.
                </p>
            </div>

            <p id="ag-error" class="field-error hidden"></p>
        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button"
                    class="btn-secondary"
                    data-modal-close>
                Cancel
            </button>

            <button type="submit"
                    id="ag-submit"
                    class="btn-primary">
                Add gateway
            </button>
        </div>
    </form>
</dialog>

<!-- Rotate API Key Modal -->
<dialog id="rotate-key-modal"
        class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-md"
        aria-labelledby="rotate-key-title">

    <form id="rotate-key-form" class="flex flex-col">

        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="rotate-key-title"
                class="text-3xl font-semibold text-text-primary">
                Rotate API key
            </h2>

            <button type="button"
                    class="btn-icon"
                    data-modal-close
                    aria-label="Close dialog">
                <?= icon('close', 'w-5 h-5') ?>
            </button>
        </div>

        <div class="px-6 py-5 space-y-4">

            <p id="rotate-key-target"
               class="text-md text-text-secondary"></p>

            <div id="rk-public-key-field" class="hidden">
                <label for="rk-public-key" class="field-label"><span id="rk-public-key-label">New Key ID</span></label>

                <input type="text"
                       id="rk-public-key"
                       class="field-input font-mono"
                       placeholder="rzp_live_…"
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    This provider issues its public identifier and secret as a pair — update both together when regenerating. Leave blank to keep the current one.
                </p>
            </div>

            <div id="rk-payout-account-field" class="hidden">
                <label for="rk-payout-account" class="field-label">
                    RazorpayX payout account number
                </label>

                <input type="text"
                       id="rk-payout-account"
                       class="field-input font-mono"
                       placeholder="e.g. 7878780080316316"
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    Leave blank to keep the current one.
                </p>
            </div>

            <div id="rk-sandbox-field" class="hidden">
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" id="rk-sandbox-mode" class="rounded">
                    <span class="text-md text-text-primary">Sandbox / test mode</span>
                </label>
                <p class="field-help">
                    Only change this if you're switching this gateway between test and live credentials.
                </p>
            </div>

            <div>
                <label for="rk-key" class="field-label">
                    New API key
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text"
                       id="rk-key"
                       class="field-input font-mono"
                       minlength="8"
                       required
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    The previous key stops being valid immediately.
                </p>
            </div>

            <p id="rk-error" class="field-error hidden"></p>
        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button"
                    class="btn-secondary"
                    data-modal-close>
                Cancel
            </button>

            <button type="submit"
                    id="rk-submit"
                    class="btn-primary">
                Rotate key
            </button>
        </div>
    </form>
</dialog>

<!-- Edit Limits Modal -->
<dialog id="edit-limits-modal"
        class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-md"
        aria-labelledby="edit-limits-title">

    <form id="edit-limits-form" class="flex flex-col">

        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="edit-limits-title"
                class="text-3xl font-semibold text-text-primary">
                Priority &amp; limits
            </h2>

            <button type="button"
                    class="btn-icon"
                    data-modal-close
                    aria-label="Close dialog">
                <?= icon('close', 'w-5 h-5') ?>
            </button>
        </div>

        <div class="px-6 py-5 space-y-4">

            <p id="edit-limits-target" class="text-md text-text-secondary"></p>

            <div>
                <label for="el-priority" class="field-label">
                    Priority
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="number"
                       id="el-priority"
                       class="field-input"
                       min="0"
                       max="9999"
                       step="1"
                       required>

                <p class="field-help">
                    Lower numbers are tried first when routing a new transaction.
                </p>
            </div>

            <div>
                <label for="el-per-transaction-limit" class="field-label">
                    Per-transaction limit (₹)
                </label>

                <input type="number"
                       id="el-per-transaction-limit"
                       class="field-input"
                       min="0"
                       step="0.01"
                       placeholder="Leave blank for unlimited">

                <p class="field-help">
                    A single transaction larger than this skips this gateway, regardless of remaining capacity.
                </p>
            </div>

            <div>
                <p class="field-label mb-1.5">Ticket size (₹)</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="el-min-ticket" class="text-xs text-text-secondary">Minimum</label>
                        <input type="number"
                               id="el-min-ticket"
                               class="field-input"
                               min="0"
                               step="0.01"
                               placeholder="No minimum">
                    </div>
                    <div>
                        <label for="el-max-ticket" class="text-xs text-text-secondary">Maximum</label>
                        <input type="number"
                               id="el-max-ticket"
                               class="field-input"
                               min="0"
                               step="0.01"
                               placeholder="No maximum">
                    </div>
                </div>
                <p class="field-help">
                    A transaction outside this band skips this gateway entirely — separate from the hourly/daily/monthly capacity limits below and the per-transaction ceiling above.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label for="el-hourly-limit" class="field-label">
                        Hourly limit (₹)
                    </label>

                    <input type="number"
                           id="el-hourly-limit"
                           class="field-input"
                           min="0"
                           step="0.01"
                           placeholder="Unlimited">
                </div>

                <div>
                    <label for="el-daily-limit" class="field-label">
                        Daily limit (₹)
                    </label>

                    <input type="number"
                           id="el-daily-limit"
                           class="field-input"
                           min="0"
                           step="0.01"
                           placeholder="Unlimited">
                </div>

                <div>
                    <label for="el-monthly-limit" class="field-label">
                        Monthly limit (₹)
                    </label>

                    <input type="number"
                           id="el-monthly-limit"
                           class="field-input"
                           min="0"
                           step="0.01"
                           placeholder="Unlimited">
                </div>
            </div>

            <p class="field-help">
                Our-side caps on total amount routed through this gateway per hour / calendar day (UTC) / calendar month. Each resets automatically at the start of its own window.
            </p>

            <div class="flex items-center gap-5 pt-1">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="el-payin-enabled" class="rounded">
                    <span class="text-md text-text-primary">PayIn enabled</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="el-payout-enabled" class="rounded">
                    <span class="text-md text-text-primary">PayOut enabled</span>
                </label>
            </div>

            <div class="rounded-md border border-border bg-surface-muted p-3">
                <label class="flex items-start gap-2.5 cursor-pointer">
                    <input type="checkbox" id="el-is-mock" class="rounded mt-0.5">
                    <span>
                        <span class="block text-md text-text-primary">Mock / test gateway</span>
                        <span class="block text-sm text-text-secondary mt-0.5">
                            Never calls a real provider — every transaction routed here settles instantly (success) for local/test use. Only enable this for a gateway you know isn't backed by real credentials; a real provider (Razorpay/Cashfree) missing valid credentials is otherwise correctly treated as ineligible, never simulated.
                        </span>
                    </span>
                </label>
            </div>

            <p id="el-error" class="field-error hidden"></p>
        </div>

        <div class="flex items-center justify-between gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button"
                    class="btn-ghost !px-3 !py-1.5"
                    id="el-reset-usage">
                Reset today's usage
            </button>

            <div class="flex items-center gap-3">
                <button type="button"
                        class="btn-secondary"
                        data-modal-close>
                    Cancel
                </button>

                <button type="submit"
                        id="el-submit"
                        class="btn-primary">
                    Save
                </button>
            </div>
        </div>
    </form>
</dialog>

<!-- Configure Webhook Modal -->
<dialog id="configure-webhook-modal"
        class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-md"
        aria-labelledby="configure-webhook-title">

    <form id="configure-webhook-form" class="flex flex-col">

        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="configure-webhook-title"
                class="text-3xl font-semibold text-text-primary">
                Configure webhook
            </h2>

            <button type="button"
                    class="btn-icon"
                    data-modal-close
                    aria-label="Close dialog">
                <?= icon('close', 'w-5 h-5') ?>
            </button>
        </div>

        <div class="px-6 py-5 space-y-4">

            <div>
                <label class="field-label">Receiver URL</label>

                <input type="text"
                       id="cw-url"
                       class="field-input font-mono"
                       readonly>

                <p class="field-help">
                    Paste this into the provider's dashboard as the webhook/callback URL for this gateway.
                </p>
            </div>

            <div>
                <label for="cw-secret" class="field-label">
                    Webhook signing secret
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>

                <input type="text"
                       id="cw-secret"
                       class="field-input font-mono"
                       minlength="16"
                       placeholder="whsec_…"
                       required
                       autocomplete="off"
                       spellcheck="false">

                <p class="field-help">
                    Stored encrypted. Used only to verify this gateway's inbound webhook signatures — the previous secret stops being valid immediately once saved.
                </p>
            </div>

            <p id="cw-error" class="field-error hidden"></p>
        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button"
                    class="btn-secondary"
                    data-modal-close>
                Cancel
            </button>

            <button type="submit"
                    id="cw-submit"
                    class="btn-primary">
                Save secret
            </button>
        </div>
    </form>
</dialog>

<?php

render_modal(
    'delete-gateway-modal',
    'Remove this gateway?',
    '<p id="delete-gateway-body">This gateway configuration will be permanently removed. This cannot be undone.</p>',
    'Remove gateway',
    'id="delete-gateway-confirm-btn"',
    true
);

render_modal(
    'reset-usage-modal',
    "Reset today's usage?",
    '<p id="reset-usage-body">This gateway\'s used amount and transaction count for today will be set back to zero, freeing up its full daily limit again.</p>',
    'Reset usage',
    'id="reset-usage-confirm-btn"',
    true
);

render_modal(
    'clear-pause-modal',
    'Clear auto-pause?',
    '<p id="clear-pause-body"></p>',
    'Clear pause',
    'id="clear-pause-confirm-btn"'
);

?>