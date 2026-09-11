<?php
/** Admin only. Read-only visualization of the ALREADY-EXISTING routing
 * engine (includes/gateway_selector.php::select_and_reserve_gateway()) —
 * no new backend logic, just the real selection order and today's usage
 * against it. Server-rendered, same pattern as pages/admin/audit-log.php. */
require_once __DIR__ . '/../../includes/gateway_selector.php';
require_once __DIR__ . '/../../includes/banner.php';

$pdo = db();
$stmt = $pdo->query(
    'SELECT id, display_name, provider, status, priority, daily_limit_amount, hourly_limit_amount, monthly_limit_amount, per_transaction_limit_amount, auto_paused_until, sandbox_mode
     FROM payment_gateways
     ORDER BY priority ASC, id ASC'
);
$gateways = $stmt->fetchAll();

foreach ($gateways as &$g) {
    $g['daily_usage'] = gateway_daily_usage_snapshot($pdo, (int) $g['id']);
    $g['auto_paused'] = $g['auto_paused_until'] !== null && $g['auto_paused_until'] > gmdate('Y-m-d H:i:s');
}
unset($g);
?>
<?php render_hero_banner(
    $user,
    'Routing & switching',
    'How a PayIn/PayOut picks a gateway — the default order below, before each merchant\'s own assignment narrows it.'
); ?>

<div class="card mb-5">
    <h2 class="card-title mb-3">How this works</h2>
    <ol class="space-y-2 text-md text-text-secondary list-decimal pl-5">
        <li>Each merchant is only ever routed through gateways an admin has explicitly assigned them (<a href="/admin/users" class="text-brand-emphasis underline">Users → Gateways</a>) — a merchant with nothing assigned cannot create a PayIn/PayOut at all.</li>
        <li>Within a merchant's assigned gateways, they're tried in <em>that merchant's own</em> priority order — the priority shown below is only each gateway's default/fallback order, used to pre-fill priority when a gateway is first assigned to a merchant.</li>
        <li>A gateway is skipped if the transaction exceeds its per-transaction limit, or would push its hourly, daily, or monthly usage over its configured limit — these limits are shared platform-wide across every merchant using that gateway.</li>
        <li>The first assigned gateway that fits is used — no manual intervention needed when one gateway is busy or temporarily unavailable.</li>
        <li>A gateway that fails 3 confirmed transactions in a row is automatically paused for 15 minutes and skipped until then, regardless of priority.</li>
        <li>Change a gateway's own config/limits from <a href="/admin/gateways" class="text-brand-emphasis underline">Payment gateways</a>, or a merchant's assignment/priority from <a href="/admin/users" class="text-brand-emphasis underline">Users</a> — this page is a view only.</li>
    </ol>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Order</th>
                    <th scope="col">Gateway</th>
                    <th scope="col">Status</th>
                    <th scope="col">Today's usage</th>
                    <th scope="col">Limits configured</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$gateways): ?>
                    <tr><td colspan="5">
                        <div class="empty-state">
                            <span class="empty-state-icon"><?= icon('inbox', 'w-6 h-6') ?></span>
                            <p class="empty-state-title">No gateways configured</p>
                            <p class="empty-state-body">Add one from Payment gateways to start routing transactions.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($gateways as $i => $g): ?>
                    <tr class="<?= $g['status'] !== 'active' || $g['auto_paused'] ? 'opacity-60' : '' ?>">
                        <td class="font-mono text-sm"><?= e((string) ($i + 1)) ?></td>
                        <td>
                            <span class="block text-md text-text-primary"><?= e($g['display_name']) ?></span>
                            <span class="block text-sm text-text-secondary"><?= e(ucfirst($g['provider'])) ?> · <?= $g['sandbox_mode'] ? 'Sandbox' : 'Live' ?> · priority <?= e((string) $g['priority']) ?></span>
                        </td>
                        <td>
                            <span class="<?= $g['status'] === 'active' ? 'badge-success' : 'badge-neutral' ?>"><?= e(ucfirst($g['status'])) ?></span>
                            <?php if ($g['auto_paused']): ?><span class="badge-warning block mt-1">Auto-paused</span><?php endif; ?>
                        </td>
                        <td class="text-sm">
                            <?php if ($g['daily_limit_amount'] === null): ?>
                                <span class="text-text-secondary">No daily limit</span>
                            <?php else: ?>
                                <?= e(money_format($g['daily_usage']['used_amount'], 'INR')) ?> / <?= e(money_format($g['daily_limit_amount'], 'INR')) ?>
                                <span class="block text-text-secondary mt-0.5"><?= e((string) $g['daily_usage']['transaction_count']) ?> txns today</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-text-secondary">
                            <?php
                            $configured = [];
                            if ($g['per_transaction_limit_amount'] !== null) $configured[] = 'per-txn';
                            if ($g['hourly_limit_amount'] !== null) $configured[] = 'hourly';
                            if ($g['daily_limit_amount'] !== null) $configured[] = 'daily';
                            if ($g['monthly_limit_amount'] !== null) $configured[] = 'monthly';
                            echo $configured ? e(implode(', ', $configured)) : 'None';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
