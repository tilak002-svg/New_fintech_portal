<?php
/**
 * Expects $user (array) and $route (string) in scope, set by public/index.php.
 */
require_once __DIR__ . '/../public/assets/icons/icons.php';

$customerNav = [
    ['dashboard', 'dashboard', 'Dashboard'],
    ['wallet', 'wallet', 'Wallet'],
    ['deposits', 'deposit', 'Deposits'],
    ['withdrawals', 'withdrawal', 'Withdrawals'],
    ['transactions', 'transactions', 'Transactions'],
    ['identity-vault', 'profile', 'Identity Vault'],
    ['kyc-verification', 'shield', 'KYC Verification'],
    ['support', 'support', 'Support'],
    ['notifications', 'notification', 'Notifications'],
];

$adminNav = [
    ['dashboard', 'dashboard', 'Dashboard'],
    ['transactions', 'transactions', 'Transactions'],
    ['admin/users', 'users', 'Customers'],
    ['admin/gateways', 'gateway', 'Payment gateways', [
        ['admin/gateways', 'gateway', 'Manage gateways'],
        ['admin/gateways/docs', 'documentation', 'Gateway API docs'],
        ['admin/api-integration-docs', 'documentation', 'Customer API docs'],
        ['admin/treasury', 'treasury', 'Treasury Node'],
    ]],
    ['admin/support', 'support', 'Support inbox'],
    ['admin/audit-log', 'shield', 'Audit log'],
    ['notifications', 'notification', 'Notifications'],
];

if ($user['role'] === 'operator') {
    // Payment gateway management is admin-only at the route level
    // (public/index.php) â€” don't show operators a link that 403s.
    $adminNav = array_values(array_filter($adminNav, fn($entry) => $entry[0] !== 'admin/gateways'));
}
$navItems = in_array($user['role'], ['admin', 'operator'], true) ? $adminNav : $customerNav;
$footerNav = [
    ['profile', 'profile', 'Profile'],
    ['settings', 'settings', 'Settings'],
];

/**
 * Renders one nav entry. Entries with a 4th (children) element render as
 * an expandable group â€” a labeled toggle button plus an indented sub-list â€”
 * rather than a direct link. Expanded by default whenever the current
 * route matches the group or one of its children.
 */
function render_nav_entry(array $entry, string $route, int $index = 0): void
{
    [$routeKey, $iconName, $label] = $entry;
    $children = $entry[3] ?? null;

    if (!$children) {
        ?>
        <a href="/<?= e($routeKey) ?>" class="nav-link" <?= $route === $routeKey ? 'aria-current="page"' : '' ?>>
            <span class="nav-link-indicator" aria-hidden="true"></span>
            <?= icon($iconName, 'w-5 h-5 shrink-0') ?>
            <span><?= e($label) ?></span>
        </a>
        <?php
        return;
    }

    $childRoutes = array_column($children, 0);
    $isActiveGroup = in_array($route, $childRoutes, true);
    $panelId = 'nav-group-' . $index;
    ?>
    <div>
        <button type="button" class="nav-link w-full text-left nav-group-toggle" aria-expanded="<?= $isActiveGroup ? 'true' : 'false' ?>" aria-controls="<?= e($panelId) ?>">
            <span class="nav-link-indicator" aria-hidden="true"></span>
            <?= icon($iconName, 'w-5 h-5 shrink-0') ?>
            <span class="flex-1"><?= e($label) ?></span>
            <?= icon('chevron-down', 'w-4 h-4 shrink-0 transition-transform duration-instant nav-group-chevron') ?>
        </button>
        <div id="<?= e($panelId) ?>" class="nav-group-panel pl-4 space-y-0.5 <?= $isActiveGroup ? '' : 'hidden' ?>">
            <?php foreach ($children as [$childRoute, $childIcon, $childLabel]): ?>
                <a href="/<?= e($childRoute) ?>" class="nav-link !py-2 text-sm" <?= $route === $childRoute ? 'aria-current="page"' : '' ?>>
                    <span class="nav-link-indicator" aria-hidden="true"></span>
                    <?= icon($childIcon, 'w-4 h-4 shrink-0') ?>
                    <span><?= e($childLabel) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
?>
<aside id="app-sidebar" class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full lg:translate-x-0 transition-transform duration-fast ease-in-out bg-surface-strong flex flex-col" aria-label="Primary">
            <div class="flex items-center gap-3 mx-4 mt-4 mb-2 px-4 py-4 rounded-lg bg-[#170b2e]">
        <span class="relative flex items-center justify-center w-11 h-11 shrink-0" aria-hidden="true">
            <svg viewBox="0 0 40 40" class="w-11 h-11 drop-shadow-[0_4px_10px_rgba(139,63,209,0.55)]" fill="none">
                <defs>
                    <linearGradient id="brand-logo-grad-top" x1="5" y1="4" x2="35" y2="20" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#c084fc"/>
                        <stop offset="1" stop-color="#8b3fd1"/>
                    </linearGradient>
                    <linearGradient id="brand-logo-grad-mid" x1="5" y1="18" x2="35" y2="30" gradientUnits="userSpaceOnUse">
                        <stop offset="0" stop-color="#8b3fd1"/>
                        <stop offset="1" stop-color="#4e148c"/>
                    </linearGradient>
                </defs>
                <polygon points="20,22 35,14.5 35,20.5 20,28 5,20.5 5,14.5" fill="url(#brand-logo-grad-mid)" opacity="0.45"/>
                <polygon points="20,28 35,20.5 35,26.5 20,34 5,26.5 5,20.5" fill="url(#brand-logo-grad-mid)" opacity="0.28"/>
                <polygon points="20,4 35,12 20,20 5,12" fill="url(#brand-logo-grad-top)"/>
            </svg>
        </span>
        <span class="leading-tight">
            <span class="block text-2xl font-bold text-white tracking-tight">Verapay</span>
                        <span class="block text-[10px] font-semibold tracking-[0.18em] text-[#d8b4fe] uppercase">Fintech Portal</span>
        </span>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 space-y-0.5" aria-label="Main navigation">
        <?php foreach ($navItems as $i => $entry): ?>
            <?php render_nav_entry($entry, $route, $i); ?>
        <?php endforeach; ?>
    </nav>

    <div class="px-3 py-3 border-t border-white/10 space-y-0.5">
        <?php foreach ($footerNav as [$routeKey, $iconName, $label]): ?>
            <a href="/<?= e($routeKey) ?>" class="nav-link" <?= $route === $routeKey ? 'aria-current="page"' : '' ?>>
                <span class="nav-link-indicator" aria-hidden="true"></span>
                <?= icon($iconName, 'w-5 h-5 shrink-0') ?>
                <span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
        <form action="/api/auth/logout.php" method="post" data-confirm-off>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="nav-link w-full text-left">
                <span class="nav-link-indicator" aria-hidden="true"></span>
                <?= icon('logout', 'w-5 h-5 shrink-0') ?>
                <span>Log out</span>
            </button>
        </form>
    </div>
</aside>
<button type="button" id="sidebar-backdrop" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" aria-hidden="true" tabindex="-1"></button>
