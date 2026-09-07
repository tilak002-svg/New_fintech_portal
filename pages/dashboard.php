<?php
/** Expects $user in scope (set by public/index.php). */
require_once __DIR__ . '/../includes/banner.php';
$isOperator = in_array($user['role'], ['admin', 'operator'], true);
$extraScripts = ['/assets/js/charts.js', '/assets/js/pages/dashboard.js'];
?>

<?php if ($isOperator): ?>

<?php
render_hero_banner(
    $user,
    'Operations overview',
    'Platform-wide payment activity and operational health.'
);

$reportCardMeta = [
    'total' => ['label' => 'Total', 'icon' => 'transactions', 'accent' => 'border-l-brand', 'iconBg' => 'bg-brand-muted', 'iconText' => 'text-brand'],
    'success' => ['label' => 'Successful', 'icon' => 'check-circle', 'accent' => 'border-l-success', 'iconBg' => 'bg-success-bg', 'iconText' => 'text-success'],
    'pending' => ['label' => 'Pending', 'icon' => 'clock', 'accent' => 'border-l-warning', 'iconBg' => 'bg-warning-bg', 'iconText' => 'text-warning'],
    'failed' => ['label' => 'Failed', 'icon' => 'alert-circle', 'accent' => 'border-l-danger', 'iconBg' => 'bg-danger-bg', 'iconText' => 'text-danger'],
];
function render_report_cards(string $prefix, string $type, array $meta): void {
    foreach ($meta as $key => $m) {
        $href = '/transactions?type=' . urlencode($type) . ($key === 'total' ? '' : '&status=' . urlencode($key));
        ?>
        <a href="<?= e($href) ?>" class="card card-interactive !p-5 border-l-4 <?= $m['accent'] ?>">
            <div class="flex items-center gap-3">
                <span class="flex items-center justify-center w-9 h-9 rounded-sm <?= $m['iconBg'] ?> <?= $m['iconText'] ?> shrink-0"><?= icon($m['icon'], 'w-5 h-5') ?></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-text-secondary truncate"><?= e($m['label']) ?></p>
                    <p class="text-3xl font-semibold text-text-primary" id="<?= e($prefix) ?>-<?= e($key) ?>-amount"><span class="skeleton inline-block h-6 w-20 rounded-sm align-middle"></span></p>
                </div>
            </div>
            <p class="text-sm text-text-secondary mt-2" id="<?= e($prefix) ?>-<?= e($key) ?>-count"></p>
        </a>
    <?php }
}
?>

<?php if ($user['role'] === 'admin'): ?>
<div class="card !p-5 mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4" id="base-url-card">
    <div class="min-w-0">
        <p class="text-sm font-medium text-text-secondary mb-1">Platform API Base URL</p>
        <p class="font-mono text-md text-text-primary truncate" id="base-url-value"><span class="skeleton inline-block h-5 w-64 rounded-sm align-middle"></span></p>
        <p class="text-xs text-text-secondary mt-1">Shown to every customer in API Access and API documentation.</p>
    </div>
    <button type="button" class="btn-secondary shrink-0" data-modal-trigger="edit-base-url-modal"><?= icon('settings', 'w-4 h-4') ?> Edit</button>
</div>

<dialog id="edit-base-url-modal" class="rounded-md p-0 backdrop:bg-black/40 w-full max-w-lg" aria-labelledby="edit-base-url-title">
    <div class="flex flex-col">
        <div class="flex items-start justify-between gap-4 px-6 py-5 border-b border-border">
            <h2 id="edit-base-url-title" class="text-3xl font-semibold text-text-primary">Edit API Base URL</h2>
            <button type="button" class="btn-icon" data-modal-close aria-label="Close dialog"><?= icon('close', 'w-5 h-5') ?></button>
        </div>
        <div class="px-6 py-5 space-y-4">
            <div class="rounded-md border border-warning/30 bg-warning-bg px-4 py-3 flex items-start gap-2.5">
                <?= icon('shield', 'w-5 h-5 text-warning shrink-0 mt-0.5') ?>
                <p class="text-sm text-text-primary">This URL is displayed to every customer and used throughout the API documentation. Changing it here does <strong>not</strong> configure DNS, SSL, or your reverse proxy — the new domain must already resolve and serve this app before you save it here, or customer integrations will start failing immediately.</p>
            </div>
            <div>
                <label for="base-url-input" class="field-label">API Base URL</label>
                <input type="text" id="base-url-input" class="field-input font-mono" placeholder="https://api.yourdomain.com/api/v1">
                <p class="field-help">Leave blank and save to revert to the default (derived from this app's own URL).</p>
            </div>
            <p id="base-url-error" class="field-error hidden"></p>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-border bg-surface-muted rounded-b-md">
            <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
            <button type="button" id="base-url-save" class="btn-primary">Save</button>
        </div>
    </div>
</dialog>
<?php endif; ?>

<div id="dashboard-root" data-role="<?= e($user['role']) ?>">
    <!-- Primary balance panel -->
    <a href="/transactions" class="card card-interactive !p-6 border-l-4 border-l-brand mb-6" id="primary-balance-card">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <span class="flex items-center justify-center w-12 h-12 rounded-md bg-brand-muted text-brand shrink-0"><?= icon('wallet', 'w-6 h-6') ?></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-text-secondary" id="primary-balance-label">Today's transaction volume</p>
                    <p class="text-4xl font-semibold text-text-primary" id="primary-balance-amount"><span class="skeleton inline-block h-9 w-40 rounded-sm align-middle"></span></p>
                </div>
            </div>
            <div class="flex items-center gap-6 sm:pl-6 sm:border-l sm:border-border" id="primary-balance-breakdown">
                <div>
                    <p class="text-sm text-text-secondary" id="primary-balance-sub1-label">—</p>
                    <p class="text-lg font-semibold text-text-primary" id="primary-balance-sub1-value">—</p>
                </div>
                <div>
                    <p class="text-sm text-text-secondary" id="primary-balance-sub2-label">—</p>
                    <p class="text-lg font-semibold text-text-primary" id="primary-balance-sub2-value">—</p>
                </div>
                <?= icon('chevron-right', 'w-5 h-5 text-text-secondary hidden sm:block shrink-0') ?>
            </div>
        </div>
    </a>

    <!-- Deposits report — legacy wallet activity (customers no longer have
         a browser deposit/withdrawal flow, see PayIns/PayOuts below). -->
    <h2 class="text-3xl font-semibold text-text-primary mb-3">Deposits report</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6" id="deposits-report-grid">
        <?php render_report_cards('dep', 'deposit', $reportCardMeta); ?>
    </div>

    <!-- Withdrawals report -->
    <h2 class="text-3xl font-semibold text-text-primary mb-3">Withdrawals report</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-6" id="withdrawals-report-grid">
        <?php render_report_cards('wd', 'withdrawal', $reportCardMeta); ?>
    </div>

    <!-- PayIn/PayOut API activity — a compact 2-card summary rather than a
         full 4-status breakdown grid, to stay within this page's own
         documented density ceiling (see docs/DESIGN_SYSTEM.md §3.2). -->
    <h2 class="text-3xl font-semibold text-text-primary mb-3">API activity today</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-6">
        <a href="/admin/payins" class="card card-interactive !p-5 border-l-4 border-l-success">
            <div class="flex items-center gap-3">
                <span class="flex items-center justify-center w-9 h-9 rounded-sm bg-success-bg text-success shrink-0"><?= icon('deposit', 'w-5 h-5') ?></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-text-secondary truncate">PayIns today</p>
                    <p class="text-3xl font-semibold text-text-primary" id="payins-today-amount"><span class="skeleton inline-block h-6 w-20 rounded-sm align-middle"></span></p>
                </div>
            </div>
            <p class="text-sm text-text-secondary mt-2" id="payins-today-count"></p>
        </a>
        <a href="/admin/payouts" class="card card-interactive !p-5 border-l-4 border-l-warning">
            <div class="flex items-center gap-3">
                <span class="flex items-center justify-center w-9 h-9 rounded-sm bg-warning-bg text-warning shrink-0"><?= icon('withdrawal', 'w-5 h-5') ?></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-text-secondary truncate">PayOuts today</p>
                    <p class="text-3xl font-semibold text-text-primary" id="payouts-today-amount"><span class="skeleton inline-block h-6 w-20 rounded-sm align-middle"></span></p>
                </div>
            </div>
            <p class="text-sm text-text-secondary mt-2" id="payouts-today-count"></p>
        </a>
    </div>

    <!-- Gateway health -->
    <div class="card mb-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="card-title">Gateway health</h2>
            <a href="/admin/routing" class="text-sm font-medium text-brand hover:underline">Routing &amp; switching</a>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base" id="gateway-health-table">
                <thead>
                    <tr>
                        <th scope="col">Gateway</th>
                        <th scope="col">Status</th>
                        <th scope="col">Today's usage</th>
                        <th scope="col">Success rate</th>
                    </tr>
                </thead>
                <tbody id="gateway-health-tbody">
                    <tr><td colspan="4" class="text-center py-6 text-text-secondary">Loading gateway health…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Analytics — legacy wallet activity. -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
        <div class="card">
            <h2 class="card-title mb-1">Deposit analytics</h2>
            <p class="card-subtitle mb-4">Successful deposit amount, last 7 days</p>
            <div id="deposits-chart" aria-live="polite">
                <div class="skeleton h-40 w-full rounded-sm"></div>
            </div>
        </div>
        <div class="card">
            <h2 class="card-title mb-1">Withdrawal analytics</h2>
            <p class="card-subtitle mb-4">Successful withdrawal amount, last 7 days</p>
            <div id="withdrawals-chart" aria-live="polite">
                <div class="skeleton h-40 w-full rounded-sm"></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h2 class="card-title">Recent activity</h2>
            <a href="/transactions" class="text-sm font-medium text-brand hover:underline">View all transactions</a>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base" id="recent-table">
                <thead>
                    <tr>
                        <th scope="col">Customer</th>
                        <th scope="col">Reference</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="text-right">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col">Date</th>
                    </tr>
                </thead>
                <tbody id="recent-tbody">
                    <tr><td colspan="6" class="text-center py-6 text-text-secondary">Loading recent activity…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>

<?php
// Plain, minimal greeting header (not the shared gradient hero banner —
// this page uses its own lighter treatment). Same time-of-day-greeting
// mechanic as includes/banner.php: computed server-side from server time
// as a same-paint fallback, then corrected client-side to the viewer's
// actual local hour.
$hour = (int) date('G');
$greeting = 'Good evening';
if ($hour >= 5 && $hour <= 11) $greeting = 'Good morning';
elseif ($hour >= 12 && $hour <= 16) $greeting = 'Good afternoon';
elseif ($hour >= 17 && $hour <= 20) $greeting = 'Good evening';
else $greeting = 'Good night';
$firstName = trim(strtok($user['name'], ' '));
?>
<div id="dashboard-root" data-role="<?= e($user['role']) ?>">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-4xl font-semibold text-text-primary"><span id="plain-greeting"><?= e($greeting) ?></span>, <?= e($firstName) ?></h1>
            <p class="text-md text-text-secondary mt-1">Your PayIn/PayOut summary</p>
        </div>
        <div class="flex items-center gap-2 rounded-md border border-border bg-surface-raised px-4 py-2.5 shrink-0">
            <?= icon('calendar', 'w-4 h-4 text-text-secondary') ?>
            <span id="plain-date" class="text-sm font-medium text-text-primary font-mono"><?= e(date('d/m/Y')) ?></span>
        </div>
    </div>

    <div class="mb-5">
        <span class="inline-block text-md font-semibold text-brand border-b-2 border-brand pb-2">Your Overview</span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-6">
        <div class="card !p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-text-secondary">Total PayIns</p>
                <span class="badge-neutral">All</span>
            </div>
            <p class="text-3xl font-semibold text-text-primary" id="stat-total-payins"><span class="skeleton inline-block h-7 w-24 rounded-sm align-middle"></span></p>
        </div>
        <div class="card !p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-text-secondary">Today's PayIns</p>
                <span class="badge-success">Today</span>
            </div>
            <p class="text-3xl font-semibold text-text-primary" id="stat-today-payins"><span class="skeleton inline-block h-7 w-24 rounded-sm align-middle"></span></p>
        </div>
        <div class="card !p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-text-secondary">Available Balance</p>
                <span class="badge-neutral">All</span>
            </div>
            <p class="text-3xl font-semibold text-text-primary" id="stat-available-balance"><span class="skeleton inline-block h-7 w-24 rounded-sm align-middle"></span></p>
        </div>
        <div class="card !p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-text-secondary">Pending Balance</p>
                <span class="badge-neutral">All</span>
            </div>
            <p class="text-3xl font-semibold text-text-primary" id="stat-pending-balance"><span class="skeleton inline-block h-7 w-24 rounded-sm align-middle"></span></p>
        </div>
        <div class="card !p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-text-secondary">Total PayOuts</p>
                <span class="badge-neutral">All</span>
            </div>
            <p class="text-3xl font-semibold text-text-primary" id="stat-total-payouts"><span class="skeleton inline-block h-7 w-24 rounded-sm align-middle"></span></p>
        </div>
        <div class="card !p-5">
            <div class="flex items-center justify-between mb-2">
                <p class="text-sm font-medium text-text-secondary">Today's PayOuts</p>
                <span class="badge-success">Today</span>
            </div>
            <p class="text-3xl font-semibold text-text-primary" id="stat-today-payouts"><span class="skeleton inline-block h-7 w-24 rounded-sm align-middle"></span></p>
        </div>
    </div>

    <div class="card mb-6" id="onboarding-card">
        <h2 class="card-title mb-1">Integration setup</h2>
        <p class="card-subtitle mb-4">What's done, and what's left before you're fully live.</p>
        <ul class="space-y-2.5" id="onboarding-checklist"></ul>
    </div>

    <div class="card">
        <div class="flex items-center justify-between mb-4">
            <h2 class="card-title">Recent activity</h2>
            <a href="/transactions" class="text-sm font-medium text-brand hover:underline">View all transactions</a>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base" id="recent-table">
                <thead>
                    <tr>
                        <th scope="col">Reference</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="text-right">Amount</th>
                        <th scope="col">Status</th>
                        <th scope="col">Date</th>
                    </tr>
                </thead>
                <tbody id="recent-tbody">
                    <tr><td colspan="5" class="text-center py-6 text-text-secondary">Loading recent activity…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    (function () {
        var el = document.getElementById('plain-greeting');
        if (!el) return;
        var hour = new Date().getHours();
        var greeting = 'Good night';
        if (hour >= 5 && hour <= 11) greeting = 'Good morning';
        else if (hour >= 12 && hour <= 16) greeting = 'Good afternoon';
        else if (hour >= 17 && hour <= 20) greeting = 'Good evening';
        el.textContent = greeting;
        var dateEl = document.getElementById('plain-date');
        if (dateEl) {
            var d = new Date();
            var pad = function (n) { return String(n).padStart(2, '0'); };
            dateEl.textContent = pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
        }
    })();
</script>
<?php endif; ?>
