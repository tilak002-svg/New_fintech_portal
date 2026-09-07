<?php
/** Customer-only. Read-only — a PayIn is only ever created by the merchant's
 * own backend calling POST /api/v1/payins/create.php, or completed by their
 * end-customer on the hosted checkout page, never from this dashboard. */
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/payins.js'];

$initialStatus = in_array($_GET['status'] ?? '', ['success', 'pending', 'failed', 'cancelled', 'refunded'], true) ? $_GET['status'] : '';

render_hero_banner($user, 'PayIns', 'Payments your API collected from your own customers.');
?>
<div class="card mb-5">
    <form id="filters-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
        <div>
            <label for="f-status" class="field-label">Status</label>
            <select id="f-status" name="status" class="field-input">
                <option value="" <?= $initialStatus === '' ? 'selected' : '' ?>>All statuses</option>
                <option value="success" <?= $initialStatus === 'success' ? 'selected' : '' ?>>Success</option>
                <option value="pending" <?= $initialStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="failed" <?= $initialStatus === 'failed' ? 'selected' : '' ?>>Failed</option>
                <option value="cancelled" <?= $initialStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                <option value="refunded" <?= $initialStatus === 'refunded' ? 'selected' : '' ?>>Refunded</option>
            </select>
        </div>
    </form>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base" id="payin-table">
            <thead>
                <tr>
                    <th scope="col">Reference</th>
                    <th scope="col">Order ID</th>
                    <th scope="col">Customer</th>
                    <th scope="col" class="text-right">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody id="payin-tbody">
                <tr><td colspan="7" class="text-center py-8 text-text-secondary">Loading PayIns…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border" id="payin-pagination"></div>
</div>
