<?php
require_once __DIR__ . '/../includes/banner.php';
$isOperator = in_array($user['role'], ['admin', 'operator'], true);
$extraScripts = ['/assets/js/pages/transactions.js'];

// Pre-selects filters when arriving via a link like /transactions?type=deposit&status=success
// (e.g. from the dashboard report cards), so the landing view actually matches what was clicked.
$initialType = in_array($_GET['type'] ?? '', ['deposit', 'withdrawal'], true) ? $_GET['type'] : '';
$initialStatus = in_array($_GET['status'] ?? '', ['success', 'pending', 'failed', 'cancelled', 'refunded'], true) ? $_GET['status'] : '';

render_hero_banner(
    $user,
    $isOperator ? 'Platform activity' : 'Your activity',
    $isOperator ? 'Every payment across the platform, filterable and exportable.' : 'A complete record of your deposits and withdrawals.'
);
?>
<div class="mb-6">
    <p class="text-md text-text-secondary"><?= $isOperator ? 'Every payment across the platform.' : 'A complete record of your deposits and withdrawals.' ?></p>
</div>

<div class="card mb-5">
    <form id="filters-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
        <div class="lg:col-span-2">
            <label for="f-search" class="field-label"><?= icon('search', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?><?= $isOperator ? 'Search reference, customer or email' : 'Search reference' ?></label>
            <input type="search" id="f-search" name="search" class="field-input" placeholder="<?= $isOperator ? 'DX-A1B2, Priya, priya@…' : 'DX-A1B2C3D4' ?>">
        </div>
        <?php if ($isOperator): ?>
        <div>
            <label for="f-customer" class="field-label">Customer</label>
            <select id="f-customer" name="user_id" class="field-input">
                <option value="">All customers</option>
            </select>
        </div>
        <?php endif; ?>
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
            <label for="f-type" class="field-label">Type</label>
            <select id="f-type" name="type" class="field-input">
                <option value="" <?= $initialType === '' ? 'selected' : '' ?>>Deposits & withdrawals</option>
                <option value="deposit" <?= $initialType === 'deposit' ? 'selected' : '' ?>>Deposits</option>
                <option value="withdrawal" <?= $initialType === 'withdrawal' ? 'selected' : '' ?>>Withdrawals</option>
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
    </form>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base" id="txn-table">
            <thead>
                <tr>
                    <?php if ($isOperator): ?><th scope="col">Customer</th><?php endif; ?>
                    <th scope="col">Reference</th>
                    <th scope="col">Type</th>
                    <th scope="col">Method</th>
                    <th scope="col" class="text-right">Amount</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody id="txn-tbody">
                <tr><td colspan="8" class="text-center py-8 text-text-secondary">Loading transactions…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border" id="txn-pagination">
    </div>
</div>
