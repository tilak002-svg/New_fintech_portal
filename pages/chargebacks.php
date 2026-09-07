<?php
require_once __DIR__ . '/../includes/banner.php';
$extraScripts = ['/assets/js/pages/chargebacks.js'];

$initialStatus = in_array($_GET['status'] ?? '', ['open', 'pending', 'won', 'lost', 'reversed', 'cancelled'], true) ? $_GET['status'] : '';

render_hero_banner($user, 'Chargebacks', 'Disputes raised against your successful PayIns — the original payment stays SUCCESS; a chargeback is tracked here as its own lifecycle.');
?>
<div class="card mb-5">
    <form id="filters-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
        <div>
            <label for="f-search" class="field-label"><?= icon('search', 'w-3.5 h-3.5 inline -mt-0.5 mr-1') ?>Search reference, order ID or chargeback ID</label>
            <input type="search" id="f-search" name="search" class="field-input" placeholder="DX-A1B2, ORD-1001, CB-…">
        </div>
        <div>
            <label for="f-status" class="field-label">Status</label>
            <select id="f-status" name="status" class="field-input">
                <option value="" <?= $initialStatus === '' ? 'selected' : '' ?>>All statuses</option>
                <option value="open" <?= $initialStatus === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="pending" <?= $initialStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="won" <?= $initialStatus === 'won' ? 'selected' : '' ?>>Won</option>
                <option value="lost" <?= $initialStatus === 'lost' ? 'selected' : '' ?>>Lost</option>
                <option value="reversed" <?= $initialStatus === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                <option value="cancelled" <?= $initialStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
        </div>
    </form>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base" id="cb-table">
            <thead>
                <tr>
                    <th scope="col">Chargeback</th>
                    <th scope="col">Original PayIn</th>
                    <th scope="col" class="text-right">Amount</th>
                    <th scope="col" class="text-right">Fee</th>
                    <th scope="col">Status</th>
                    <th scope="col">Date</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody id="cb-tbody">
                <tr><td colspan="7" class="text-center py-8 text-text-secondary">Loading chargebacks…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border" id="cb-pagination"></div>
</div>

<dialog id="cb-detail-modal" class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-lg" aria-labelledby="cb-detail-title">
    <div class="flex flex-col">
        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="cb-detail-title" class="text-3xl font-semibold text-text-primary">Chargeback detail</h2>
            <button type="button" class="btn-icon" data-modal-close aria-label="Close dialog"><?= icon('close', 'w-5 h-5') ?></button>
        </div>
        <div class="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto" id="cb-detail-body">
            <p class="text-text-secondary">Loading…</p>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button" class="btn-secondary" data-modal-close>Close</button>
        </div>
    </div>
</dialog>
