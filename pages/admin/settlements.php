<?php
/** Admin only. Server-rendered, same pattern as pages/admin/audit-log.php.
 * Read-only visibility into each merchant's OWN settlement bank (where
 * Verapay would send their net platform balance — see settlement_banks in
 * database/schema.sql) alongside their current ledger balance. No
 * settlement execution/disbursement here — that's a separate, larger
 * feature not built yet; this is visibility only. */
$page = max(1, (int) ($_GET['ap'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, sb.account_holder, sb.account_number, sb.ifsc_code, sb.bank_name,
            w.available_balance, w.pending_balance, w.currency
     FROM users u
     LEFT JOIN settlement_banks sb ON sb.user_id = u.id
     LEFT JOIN wallets w ON w.user_id = u.id
     WHERE u.role = 'customer'
     ORDER BY u.name ASC LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$total = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));

require_once __DIR__ . '/../../includes/banner.php';
?>
<?php render_hero_banner(
    $user,
    'Settlements',
    "Each merchant's own settlement bank on file and current ledger balance — where their net platform balance would be paid out."
); ?>

<div class="card !p-0 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead>
                <tr>
                    <th scope="col">Merchant</th>
                    <th scope="col">Settlement bank</th>
                    <th scope="col">Account</th>
                    <th scope="col" class="text-right">Available balance</th>
                    <th scope="col" class="text-right">Pending balance</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="5">
                        <div class="empty-state">
                            <span class="empty-state-icon"><?= icon('inbox', 'w-6 h-6') ?></span>
                            <p class="empty-state-title">No merchants yet</p>
                        </div>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <span class="block text-md text-text-primary"><?= e($row['name']) ?></span>
                            <span class="block text-sm text-text-secondary"><?= e($row['email']) ?></span>
                        </td>
                        <?php if ($row['account_number']): ?>
                            <td>
                                <span class="block text-md text-text-primary"><?= e($row['account_holder']) ?></span>
                                <span class="block text-sm text-text-secondary"><?= e($row['bank_name']) ?> · <?= e($row['ifsc_code']) ?></span>
                            </td>
                            <td class="font-mono text-sm text-text-secondary">•••• <?= e(substr($row['account_number'], -4)) ?></td>
                        <?php else: ?>
                            <td colspan="2" class="text-text-secondary">No settlement bank on file</td>
                        <?php endif; ?>
                        <td class="table-amount"><?= e(money_format($row['available_balance'] ?? '0.00', $row['currency'] ?? 'INR')) ?></td>
                        <td class="table-amount"><?= e(money_format($row['pending_balance'] ?? '0.00', $row['currency'] ?? 'INR')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-4 border-t border-border">
        <span class="text-sm text-text-secondary">Showing page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?> (<?= e((string) $total) ?> total)</span>
        <div class="flex items-center gap-2">
            <a href="/admin/settlements?ap=<?= max(1, $page - 1) ?>" class="btn-secondary !px-4 !py-2 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>">Previous</a>
            <a href="/admin/settlements?ap=<?= min($totalPages, $page + 1) ?>" class="btn-secondary !px-4 !py-2 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>" aria-disabled="<?= $page >= $totalPages ? 'true' : 'false' ?>">Next</a>
        </div>
    </div>
</div>
