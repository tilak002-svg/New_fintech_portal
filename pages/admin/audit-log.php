<?php
/** Admin only. Server-rendered (low traffic, internal page — no client fetch needed). */
$page = max(1, (int) ($_GET['ap'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$filterUserId = (int) ($_GET['user_id'] ?? 0);
$pageQuery = $filterUserId > 0 ? '&user_id=' . $filterUserId : '';

$pdo = db();

// Actions admin took ON this customer (target_type='user'), not actions
// the customer themselves took (that's actor_id, a different question) —
// matches what this filter is for: "show me everything done to this
// customer's account", e.g. suspend/reactivate/user_created.
$where = '1=1';
$params = [];
if ($filterUserId > 0) {
    $where = "a.target_type = 'user' AND a.target_id = :user_id";
    $params['user_id'] = $filterUserId;
}

$stmt = $pdo->prepare(
    "SELECT a.id, a.action, a.target_type, a.target_id, a.metadata, a.ip_address, a.created_at, u.name AS actor_name
     FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id
     WHERE {$where}
     ORDER BY a.created_at DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(":{$key}", $value, PDO::PARAM_INT);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$where}");
foreach ($params as $key => $value) {
    $countStmt->bindValue(":{$key}", $value, PDO::PARAM_INT);
}
$countStmt->execute();
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

$allCustomers = $pdo->query("SELECT id, name, email FROM users WHERE role = 'customer' ORDER BY name ASC")->fetchAll();

$actionLabels = [
    'login' => 'Signed in',
    'logout' => 'Signed out',
    'deposit_created' => 'Created a deposit',
    'withdrawal_created' => 'Created a withdrawal',
    'user_suspended' => 'Suspended a user',
    'user_reactivated' => 'Reactivated a user',
    'gateway_created' => 'Added a payment gateway',
    'gateway_status_changed' => 'Changed a gateway status',
    'gateway_set_default' => 'Set default gateway',
    'gateway_key_rotated' => 'Rotated a gateway key',
    'gateway_deleted' => 'Removed a payment gateway',
];
$actionTone = [
    'login' => 'text-info',
    'logout' => 'text-info',
    'deposit_created' => 'text-success',
    'withdrawal_created' => 'text-warning',
    'user_suspended' => 'text-danger',
    'user_reactivated' => 'text-success',
    'gateway_created' => 'text-neutral',
    'gateway_status_changed' => 'text-neutral',
    'gateway_set_default' => 'text-neutral',
    'gateway_key_rotated' => 'text-neutral',
    'gateway_deleted' => 'text-neutral',
];
require_once __DIR__ . '/../../includes/banner.php';
?>
<?php render_hero_banner(
    $user,
    'Audit log',
    'A record of sensitive operator and account actions across Verapay.'
); ?>
<div class="mb-6">
    <p class="text-md text-text-secondary">A record of sensitive operator and account actions across Verapay.</p>
</div>

<div class="card mb-5">
    <form method="GET" class="max-w-sm">
        <label for="f-customer" class="field-label">Customer</label>
        <select id="f-customer" name="user_id" class="field-input" onchange="this.form.submit()">
            <option value="">All actions</option>
            <?php foreach ($allCustomers as $c): ?>
                <option value="<?= e((string) $c['id']) ?>" <?= $filterUserId === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?> (<?= e($c['email']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Actor</th>
                    <th scope="col">Action</th>
                    <th scope="col">Target</th>
                    <th scope="col">IP address</th>
                    <th scope="col">When</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="5">
                        <div class="empty-state">
                            <span class="empty-state-icon"><?= icon('inbox', 'w-6 h-6') ?></span>
                            <p class="empty-state-title">No audit events recorded yet</p>
                            <p class="empty-state-body">Sensitive operator and account actions will show up here as they happen.</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= e($log['actor_name'] ?? 'System') ?></td>
                        <td>
                            <span class="inline-flex items-center gap-2">
                                <span class="badge-dot <?= e($actionTone[$log['action']] ?? 'text-neutral') ?>" aria-hidden="true"></span>
                                <?= e($actionLabels[$log['action']] ?? $log['action']) ?>
                            </span>
                        </td>
                        <td class="text-text-secondary"><?= e($log['target_type']) ?> #<?= e((string) $log['target_id']) ?></td>
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
            <a href="/admin/audit-log?ap=<?= max(1, $page - 1) ?><?= $pageQuery ?>" class="btn-secondary !px-4 !py-2 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a>
            <a href="/admin/audit-log?ap=<?= min($totalPages, $page + 1) ?><?= $pageQuery ?>" class="btn-secondary !px-4 !py-2 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>
</div>
