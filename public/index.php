<?php
/**
 * Front controller. Document root is /public â€” everything else (config,
 * includes, pages, database) lives one level up and is include-only,
 * never directly web-requestable.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/money.php';
require_once __DIR__ . '/assets/icons/icons.php';

bootstrap_session();

// Derived straight from the URL path rather than a rewrite-generated
// ?route= param, so this works identically under Apache (.htaccess),
// Nginx (simple try_files), and PHP's built-in dev server (which falls
// back to index.php for any missing path but does not rewrite the query
// string) without needing server-specific rewrite logic to agree.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$route = trim(rawurldecode($path), '/');
if ($route === '') {
    $route = 'dashboard';
}

// ---- Public routes (no authentication) ----
if ($route === 'login') {
    if (!empty($_SESSION['user_id'])) {
        header('Location: /dashboard');
        exit;
    }
    require __DIR__ . '/../pages/auth/login.php';
    exit;
}

if ($route === 'suspended') {
    require __DIR__ . '/../pages/auth/suspended.php';
    exit;
}

// ---- Protected routes ----
$routes = [
    'dashboard' => ['pages/dashboard.php', 'Dashboard', []],
    'wallet' => ['pages/wallet.php', 'Wallet', ['customer']],
    'deposits' => ['pages/deposits.php', 'Deposits', ['customer']],
    'withdrawals' => ['pages/withdrawals.php', 'Withdrawals', ['customer']],
    'transactions' => ['pages/transactions.php', 'Transactions', []],
    'support' => ['pages/support.php', 'Support', ['customer']],
    'notifications' => ['pages/notifications.php', 'Notifications', []],
    'profile' => ['pages/profile.php', 'Profile', []],
    'settings' => ['pages/settings.php', 'Settings', []],
    'identity-vault' => ['pages/identity-vault.php', 'Identity Vault', ['customer']],
    'kyc-verification' => ['pages/kyc-verification.php', 'KYC Verification', ['customer']],
    'admin/users' => ['pages/admin/users.php', 'Customers', ['admin', 'operator']],
    'admin/kyc-review' => ['pages/admin/kyc-review.php', 'KYC Review', ['admin']],
    'admin/gateways' => ['pages/admin/gateways.php', 'Payment gateways', ['admin']],
    'admin/gateways/docs' => ['pages/admin/gateway-docs.php', 'Gateway documentation', ['admin']],
    'admin/api-integration-docs' => ['pages/admin/api-integration-docs.php', 'Customer API integration', ['admin']],
    'admin/treasury' => ['pages/admin/treasury.php', 'Treasury Node', ['admin']],
    'admin/support' => ['pages/admin/support.php', 'Support inbox', ['admin', 'operator']],
    'admin/audit-log' => ['pages/admin/audit-log.php', 'Audit log', ['admin']],
];

if (!isset($routes[$route])) {
    http_response_code(404);
    $pageTitle = 'Not found';
    $user = require_auth();
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card text-center py-12"><p class="text-3xl font-semibold text-text-primary mb-2">Page not found</p><p class="text-md text-text-secondary mb-6">The page you requested does not exist.</p><a href="/dashboard" class="btn-primary">Back to dashboard</a></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

[$pageFile, $pageTitle, $allowedRoles] = $routes[$route];

$user = require_auth();
if (!empty($allowedRoles)) {
    require_role($user, ...$allowedRoles);
}

require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../' . $pageFile;
require __DIR__ . '/../includes/footer.php';
