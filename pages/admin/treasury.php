<?php
require_once __DIR__ . '/../../includes/banner.php';
$extraScripts = ['/assets/js/pages/admin-treasury.js'];

render_hero_banner(
    $user,
    'Treasury Node',
    'Track credits, debits and running balance across every merchant.'
);

// Every configured gateway, active or not, so a retired gateway's
// historical transactions stay filterable — same reasoning as
// pages/admin/routing.php's read-only listing.
$treasuryGateways = db()->query(
    'SELECT id, display_name, provider FROM payment_gateways ORDER BY display_name ASC'
)->fetchAll();
?>
<div class="mb-6 flex justify-end">
    <a href="#" id="treasury-download" class="btn-secondary shrink-0"><?= icon('download', 'w-4 h-4') ?>Download report</a>
</div>
<div class="card mb-5">
    <form id="treasury-filters" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
        <div class="lg:col-span-2">
            <label for="tf-search" class="field-label"><?= icon('search', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?>Search reference, merchant or email</label>
            <input type="search" id="tf-search" name="search" class="field-input" placeholder="DX-A1B2, Priya, priya@…">
        </div>
        <div>
            <label for="tf-type" class="field-label">Service type</label>
            <select id="tf-type" name="type" class="field-input">
                <option value="">All service types</option>
                <option value="deposit">Deposit</option>
                <option value="withdrawal">Withdrawal</option>
            </select>
        </div>
        <div>
            <label for="tf-gateway" class="field-label">Payment gateway</label>
            <select id="tf-gateway" name="gateway_id" class="field-input">
                <option value="">All gateways</option>
                <?php foreach ($treasuryGateways as $g): ?>
                    <option value="<?= (int) $g['id'] ?>"><?= e($g['display_name']) ?> (<?= e(ucfirst($g['provider'])) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="tf-from" class="field-label">Date from</label>
            <input type="date" id="tf-from" name="from" class="field-input">
        </div>
        <div>
            <label for="tf-to" class="field-label">Date to</label>
            <input type="date" id="tf-to" name="to" class="field-input">
        </div>
    </form>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-b border-border">
        <label class="text-sm text-text-secondary flex items-center gap-2">
            Show
            <select id="treasury-per-page" class="field-input !w-auto !py-1.5">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            entries
        </label>
    </div>
    <div class="overflow-x-auto">
        <table class="table-base" id="treasury-table">
            <thead>
                  <tr>
                    <th scope="col">Timestamp</th>
                    <th scope="col">Merchant name</th>
                    <th scope="col">Service type</th>
                    <th scope="col">Transaction ID</th>
                    <th scope="col">Gateway</th>
                    <th scope="col" class="text-right">Credit (+)</th>
                    <th scope="col" class="text-right">Debit (-)</th>
                    <th scope="col" class="text-right">Net balance</th>
                    <th scope="col">Status</th>
                </tr>
            </thead>
            <tbody id="treasury-tbody">
                <tr><td colspan="9" class="text-center py-8 text-text-secondary">Loading ledger…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border" id="treasury-pagination"></div>
</div>
