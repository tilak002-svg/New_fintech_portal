    </main>

    <footer class="px-4 py-6 sm:px-6 lg:px-8 bg-surface-raised border-t border-border-strong">
        <div class="max-w-[1440px] mx-auto flex flex-col sm:flex-row items-center justify-between gap-3">
            <span class="flex items-center gap-2 text-sm text-text-secondary">
                <img src="/assets/images/logo-mark.png" alt="" class="h-4 w-auto object-contain" aria-hidden="true">
                © <?= date('Y') ?> Verapay. All rights reserved.
            </span>
            <span class="flex items-center gap-4 text-sm">
                <a href="<?= in_array($user['role'], ['admin', 'operator'], true) ? '/admin/support' : '/support' ?>" class="text-brand hover:underline">Contact support</a>
                <span class="text-border-strong" aria-hidden="true">·</span>
                <a href="/settings" class="text-brand hover:underline">Account settings</a>
            </span>
        </div>
    </footer>
</div>

<div id="toast-region" class="fixed bottom-4 right-4 z-50 flex flex-col gap-3 w-full max-w-sm" role="region" aria-label="Notifications" aria-live="polite"></div>

<!-- Transaction detail modal — shared by Transactions, PayIns, PayOuts (customer + admin).
     Opened via any [data-view-transaction] button; populated by app.js. -->
<dialog id="transaction-detail-modal" class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-2xl" aria-labelledby="transaction-detail-title">
    <div class="flex flex-col max-h-[85vh]">
        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border shrink-0">
            <div>
                <h2 id="transaction-detail-title" class="text-3xl font-semibold text-text-primary">Transaction details</h2>
                <p id="td-subtitle" class="text-sm text-text-secondary mt-0.5"></p>
            </div>
            <button type="button" class="btn-icon shrink-0" data-modal-close aria-label="Close dialog"><?= icon('close', 'w-5 h-5') ?></button>
        </div>
        <div id="td-body" class="px-6 py-5 overflow-y-auto space-y-5">
            <p class="text-center py-8 text-text-secondary">Loading…</p>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md shrink-0">
            <button type="button" class="btn-secondary" data-modal-close>Close</button>
        </div>
    </div>
</dialog>

<script src="/assets/js/app.js?v=<?= e(ASSET_VERSION) ?>" defer></script>
<?php if (!empty($extraScripts)): foreach ($extraScripts as $src): ?>
<script src="<?= e($src) ?>?v=<?= e(ASSET_VERSION) ?>" defer></script>
<?php endforeach; endif; ?>
</body>
</html>
