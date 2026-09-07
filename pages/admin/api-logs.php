<?php
/** Admin only. Server-rendered (low traffic, internal page — no client
 * fetch needed), same pattern as pages/admin/audit-log.php. Distinct from
 * the audit log: this tracks raw merchant-API HTTP calls (bearer-token
 * authenticated requests only — see includes/auth.php::authenticate_via_bearer_token()
 * and includes/functions.php::json_response()), not admin/account actions. */
$page = max(1, (int) ($_GET['ap'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$pdo = db();
$stmt = $pdo->prepare(
    'SELECT l.id, l.method, l.endpoint, l.http_status, l.ip_address, l.created_at, u.name AS merchant_name, u.email AS merchant_email
     FROM api_logs l JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset'
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$total = (int) $pdo->query('SELECT COUNT(*) FROM api_logs')->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

function api_log_status_tone(int $status): string
{
    if ($status >= 500) return 'badge-danger';
    if ($status >= 400) return 'badge-warning';
    if ($status >= 200 && $status < 300) return 'badge-success';
    return 'badge-neutral';
}

require_once __DIR__ . '/../../includes/banner.php';
?>
<?php render_hero_banner(
    $user,
    'API logs',
    'Raw request activity from merchants calling the PayIn/PayOut API with a bearer token — distinct from the audit log, which tracks admin and account actions.'
); ?>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Merchant</th>
                    <th scope="col">Method</th>
                    <th scope="col">Endpoint</th>
                    <th scope="col">Status</th>
                    <th scope="col">IP address</th>
                    <th scope="col">When</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <span class="empty-state-icon"><?= icon('inbox', 'w-6 h-6') ?></span>
                            <p class="empty-state-title">No API calls recorded yet</p>
                            <p class="empty-state-body">Requests merchants make with a bearer token will show up here.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <span class="block text-md text-text-primary"><?= e($log['merchant_name']) ?></span>
                            <span class="block text-sm text-text-secondary"><?= e($log['merchant_email']) ?></span>
                        </td>
                        <td class="font-mono text-sm"><?= e($log['method']) ?></td>
                        <td class="font-mono text-sm text-text-secondary"><?= e($log['endpoint']) ?></td>
                        <td><span class="<?= api_log_status_tone((int) $log['http_status']) ?>"><?= e((string) $log['http_status']) ?></span></td>
                        <td class="font-mono text-sm text-text-secondary"><?= e($log['ip_address'] ?? '—') ?></td>
                        <td class="text-text-secondary whitespace-nowrap"><?= e(date('M j, Y g:ia', strtotime($log['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border">
        <span class="text-sm text-text-secondary">Showing page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?> (<?= e((string) $total) ?> total)</span>
        <div class="flex items-center gap-2">
            <a href="/admin/api-logs?ap=<?= max(1, $page - 1) ?>" class="btn-secondary !px-4 !py-2 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a>
            <a href="/admin/api-logs?ap=<?= min($totalPages, $page + 1) ?>" class="btn-secondary !px-4 !py-2 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>
</div>
