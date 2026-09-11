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

// Static asset fallback: the web server is expected to serve real files
// under /public directly, before this front controller ever runs. On hosts
// where that isn't happening (some shared-hosting rewrite configs), serve
// them here instead of falling through to the 404 page below.
if ($route !== '' && !str_ends_with($route, '.php')) {
    $assetFile = __DIR__ . '/' . $route;
    if (is_file($assetFile)) {
        static $assetMimeTypes = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'json' => 'application/json', 'webp' => 'image/webp', 'map' => 'application/json',
        ];
        $ext = strtolower(pathinfo($assetFile, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($assetMimeTypes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        readfile($assetFile);
        exit;
    }
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

// Hosted PayIn checkout — reached by a merchant's END-CUSTOMER, who has no
// Verapay account. Gated entirely by the unguessable ?session= token, not
// by auth. See includes/payin_service.php / database's payment_sessions.
if ($route === 'pay') {
    require __DIR__ . '/../pages/pay-checkout.php';
    exit;
}

// Extension-less aliases for every API endpoint (see pages/api-docs.php) —
// a real integrator shouldn't see ".php" in a path they're told to call.
// Requesting the file directly (…/create.php) still works too, served as a
// real file before this front controller ever runs; this is purely an
// additive alias, not a replacement. Each such endpoint fully guards itself
// (api_guard()/require_auth()) exactly as if reached via its own URL, so
// this dispatch is a plain include, deliberately bypassing the HTML
// header/footer/role-table below — these are JSON endpoints, not pages.
if (str_starts_with($route, 'api/')) {
    $apiFile = __DIR__ . '/' . preg_replace('/\.php$/', '', $route) . '.php';
    if (is_file($apiFile)) {
        require $apiFile;
        exit;
    }
}

// ---- Protected routes ----
$routes = [
    'dashboard' => ['pages/dashboard.php', 'Dashboard', []],
    'transactions' => ['pages/transactions.php', 'Transactions', []],
    'payins' => ['pages/payins.php', 'PayIns', ['customer']],
    'payouts' => ['pages/payouts.php', 'PayOuts', ['customer']],
    'chargebacks' => ['pages/chargebacks.php', 'Chargebacks', ['customer']],
    'api-access' => ['pages/api-access.php', 'API Access', ['customer']],
    'api-docs' => ['pages/api-docs.php', 'API documentation', ['customer']],
    'support' => ['pages/support.php', 'Support', ['customer']],
    'notifications' => ['pages/notifications.php', 'Notifications', []],
    'profile' => ['pages/profile.php', 'Profile', []],
    'settings' => ['pages/settings.php', 'Settings', []],
    'identity-vault' => ['pages/identity-vault.php', 'Identity Vault', ['customer']],
    'kyc-verification' => ['pages/kyc-verification.php', 'KYC Verification', ['customer']],
    'key-verification' => ['pages/key-verification.php', 'API & Key Verification', ['customer']],
    'admin/payins' => ['pages/admin/payins.php', 'PayIns', ['admin', 'operator']],
    'admin/payouts' => ['pages/admin/payouts.php', 'PayOuts', ['admin', 'operator']],
    'admin/chargebacks' => ['pages/admin/chargebacks.php', 'Chargebacks', ['admin', 'operator']],
    'admin/routing' => ['pages/admin/routing.php', 'Routing & switching', ['admin']],
    'admin/settlements' => ['pages/admin/settlements.php', 'Settlements', ['admin']],
    'admin/api-logs' => ['pages/admin/api-logs.php', 'API logs', ['admin']],
    'admin/webhooks' => ['pages/admin/webhooks.php', 'Webhooks', ['admin']],
    'admin/users' => ['pages/admin/users.php', 'Customers', ['admin', 'operator']],
    'admin/kyc-review' => ['pages/admin/kyc-review.php', 'KYC Review', ['admin']],
    'admin/gateways' => ['pages/admin/gateways.php', 'Payment gateways', ['admin']],
    'admin/gateways/docs' => ['pages/admin/gateway-docs.php', 'Gateway documentation', ['admin']],
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
