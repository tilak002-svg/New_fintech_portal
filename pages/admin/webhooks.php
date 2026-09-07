<?php
/** Admin only. Server-rendered, same pattern as pages/admin/audit-log.php.
 * Read-only viewer over webhook_events — fully populated already by
 * includes/gateway_webhooks.php, but had no UI surface until now. */
$page = max(1, (int) ($_GET['ap'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT w.id, w.event_id, w.gateway_txn_id, w.signature_valid, w.status, w.created_at, w.processed_at, g.display_name AS gateway_name
     FROM webhook_events w JOIN payment_gateways g ON g.id = w.gateway_id
     ORDER BY w.created_at DESC LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$events = $stmt->fetchAll();

$total = (int) $pdo->query('SELECT COUNT(*) FROM webhook_events')->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$statusTone = [
    'received' => 'badge-info',
    'processed' => 'badge-success',
    'ignored' => 'badge-neutral',
    'failed' => 'badge-danger',
];

$outPage = max(1, (int) ($_GET['op'] ?? 1));
$outPerPage = 25;
$outOffset = ($outPage - 1) * $outPerPage;

$outStmt = $pdo->prepare(
    'SELECT d.id, d.event, d.url, d.attempts, d.max_attempts, d.status, d.last_http_status, d.last_error,
            d.next_attempt_at, d.created_at, t.reference, u.name AS user_name
     FROM customer_webhook_deliveries d
     JOIN transactions t ON t.id = d.transaction_id
     JOIN users u ON u.id = d.user_id
     ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset'
);
$outStmt->bindValue(':limit', $outPerPage, PDO::PARAM_INT);
$outStmt->bindValue(':offset', $outOffset, PDO::PARAM_INT);
$outStmt->execute();
$deliveries = $outStmt->fetchAll();

$outTotal = (int) $pdo->query('SELECT COUNT(*) FROM customer_webhook_deliveries')->fetchColumn();
$outTotalPages = max(1, (int) ceil($outTotal / $outPerPage));

$outStatusTone = ['pending' => 'badge-warning', 'delivered' => 'badge-success', 'failed' => 'badge-danger'];

require_once __DIR__ . '/../../includes/banner.php';
$extraScripts = ['/assets/js/pages/admin-webhooks.js'];
?>
<?php render_hero_banner(
    $user,
    'Webhooks',
    'Inbound delivery from configured gateways, and outbound delivery to customer callback URLs.'
); ?>

<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
    <h2 class="text-2xl font-semibold text-text-primary">Outbound — customer callbacks</h2>
    <div class="flex flex-wrap items-center gap-3">
        <button type="button" id="run-reconciliation-now" class="btn-secondary"><?= icon('clock', 'w-4 h-4') ?> Reconcile pending transactions</button>
        <button type="button" id="retry-webhooks-now" class="btn-secondary"><?= icon('send', 'w-4 h-4') ?> Retry due deliveries now</button>
    </div>
</div>

<div class="card !p-0 overflow-hidden mb-8">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Merchant</th>
                    <th scope="col">Transaction</th>
                    <th scope="col">Event</th>
                    <th scope="col">Callback URL</th>
                    <th scope="col">Attempts</th>
                    <th scope="col">Status</th>
                    <th scope="col">Next attempt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$deliveries): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <span class="empty-state-icon"><?= icon('send', 'w-6 h-6') ?></span>
                            <p class="empty-state-title">No outbound deliveries yet</p>
                            <p class="empty-state-body">Callbacks to customer-configured URLs will show up here as PayIns/PayOuts settle.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($deliveries as $d): ?>
                    <tr>
                        <td><?= e($d['user_name']) ?></td>
                        <td class="font-mono text-sm"><?= e($d['reference']) ?></td>
                        <td class="font-mono text-sm text-text-secondary"><?= e($d['event']) ?></td>
                        <td class="text-sm text-text-secondary truncate max-w-[220px]" title="<?= e($d['url']) ?>"><?= e($d['url']) ?></td>
                        <td class="text-sm"><?= e((string) $d['attempts']) ?> / <?= e((string) $d['max_attempts']) ?><?= $d['last_http_status'] ? ' · HTTP ' . e((string) $d['last_http_status']) : '' ?></td>
                        <td>
                            <span class="<?= e($outStatusTone[$d['status']] ?? 'badge-neutral') ?>"><?= e(ucfirst($d['status'])) ?></span>
                            <?php if ($d['status'] === 'failed' && $d['last_error']): ?>
                                <span class="block text-xs text-text-secondary mt-0.5 truncate max-w-[180px]" title="<?= e($d['last_error']) ?>"><?= e($d['last_error']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-text-secondary whitespace-nowrap"><?= $d['status'] === 'pending' ? e(date('M j, Y g:ia', strtotime($d['next_attempt_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border">
        <span class="text-sm text-text-secondary">Showing page <?= e((string) $outPage) ?> of <?= e((string) $outTotalPages) ?> (<?= e((string) $outTotal) ?> total)</span>
        <div class="flex items-center gap-2">
            <a href="/admin/webhooks?op=<?= max(1, $outPage - 1) ?>" class="btn-secondary !px-4 !py-2 <?= $outPage <= 1 ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $outPage <= 1 ? 'true' : 'false' ?>">Previous</a>
            <a href="/admin/webhooks?op=<?= min($outTotalPages, $outPage + 1) ?>" class="btn-secondary !px-4 !py-2 <?= $outPage >= $outTotalPages ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $outPage >= $outTotalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>
</div>

<h2 class="text-2xl font-semibold text-text-primary mb-4">Inbound — gateway events</h2>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Gateway</th>
                    <th scope="col">Event ID</th>
                    <th scope="col">Gateway txn ID</th>
                    <th scope="col">Signature</th>
                    <th scope="col">Status</th>
                    <th scope="col">Received</th>
                    <th scope="col">Processed</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$events): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <span class="empty-state-icon"><?= icon('inbox', 'w-6 h-6') ?></span>
                            <p class="empty-state-title">No webhook deliveries recorded yet</p>
                            <p class="empty-state-body">Inbound events from configured gateways will show up here as they arrive.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td><?= e($ev['gateway_name']) ?></td>
                        <td class="font-mono text-sm text-text-secondary"><?= e($ev['event_id']) ?></td>
                        <td class="font-mono text-sm text-text-secondary"><?= e($ev['gateway_txn_id'] ?? '—') ?></td>
                        <td><span class="<?= $ev['signature_valid'] ? 'badge-success' : 'badge-danger' ?>"><?= $ev['signature_valid'] ? 'Valid' : 'Invalid' ?></span></td>
                        <td><span class="<?= e($statusTone[$ev['status']] ?? 'badge-neutral') ?>"><?= e(ucfirst($ev['status'])) ?></span></td>
                        <td class="text-text-secondary whitespace-nowrap"><?= e(date('M j, Y g:ia', strtotime($ev['created_at']))) ?></td>
                        <td class="text-text-secondary whitespace-nowrap"><?= $ev['processed_at'] ? e(date('M j, Y g:ia', strtotime($ev['processed_at']))) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border">
        <span class="text-sm text-text-secondary">Showing page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?> (<?= e((string) $total) ?> total)</span>
        <div class="flex items-center gap-2">
            <a href="/admin/webhooks?ap=<?= max(1, $page - 1) ?>" class="btn-secondary !px-4 !py-2 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a>
            <a href="/admin/webhooks?ap=<?= min($totalPages, $page + 1) ?>" class="btn-secondary !px-4 !py-2 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>
</div>
