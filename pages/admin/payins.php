<?php
require_once __DIR__ . '/../../includes/banner.php';
$extraScripts = ['/assets/js/pages/admin-payins.js'];

$initialStatus = in_array($_GET['status'] ?? '', ['success', 'pending', 'failed', 'cancelled', 'refunded'], true) ? $_GET['status'] : '';

render_hero_banner($user, 'PayIns', 'Merchant-API-driven payments collected across the platform — distinct from the legacy wallet Deposits shown in Transactions.');
?>
<div class="card mb-5">
    <form id="filters-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
        <div class="lg:col-span-2">
            <label for="f-search" class="field-label"><?= icon('search', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?>Search reference, order ID, merchant or customer</label>
            <input type="search" id="f-search" name="search" class="field-input" placeholder="DX-A1B2, ORD-1001, Acme Co…">
        </div>
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
        <div>
            <label for="f-sort" class="field-label">Sort by</label>
            <select id="f-sort" name="sort" class="field-input">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="amount_desc">Amount: high to low</option>
                <option value="amount_asc">Amount: low to high</option>
            </select>
        </div>
        <div>
            <label for="f-provider" class="field-label">Provider</label>
            <select id="f-provider" name="provider" class="field-input">
                <option value="">All providers</option>
                <option value="cashfree">Cashfree</option>
                <option value="razorpay">Razorpay</option>
                <option value="payu">PayU</option>
                <option value="stripe">Stripe</option>
                <option value="paypal">PayPal</option>
                <option value="other">Other</option>
            </select>
        </div>
        <div>
            <label for="f-from" class="field-label">From</label>
            <input type="date" id="f-from" name="from" class="field-input">
        </div>
        <div>
            <label for="f-to" class="field-label">To</label>
            <input type="date" id="f-to" name="to" class="field-input">
        </div>
    </form>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base" id="payin-table">
            <thead>
                <tr>
                    <th scope="col">Merchant</th>
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
                <tr><td colspan="8" class="text-center py-8 text-text-secondary">Loading PayIns…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border" id="payin-pagination"></div>
</div>
