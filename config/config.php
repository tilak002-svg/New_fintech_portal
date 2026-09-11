<?php
/**
 * Central application configuration. Included once via APP_ROOT-relative
 * paths; never web-accessible directly (lives outside /public).
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/config/env.php';

define('APP_NAME', env('APP_NAME', 'Verapay'));
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', env('APP_DEBUG', false));
define('APP_URL', rtrim(env('APP_URL', ''), '/'));

define('SESSION_LIFETIME_MINUTES', (int) env('SESSION_LIFETIME_MINUTES', 30));
define('LOGIN_MAX_ATTEMPTS', (int) env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES', (int) env('LOGIN_LOCKOUT_MINUTES', 15));

// KYC document uploads are stored outside /public (never directly web
// accessible) and served only via the authenticated download endpoint.
define('KYC_UPLOAD_DIR', APP_ROOT . '/storage/kyc-uploads');
define('KYC_UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5MB per file
define('KYC_UPLOAD_ALLOWED_MIME', ['application/pdf', 'image/jpeg', 'image/png']);

// Signing secret for platform API bearer tokens. Must stay stable across
// regenerations — rotating this invalidates every previously-issued token.
define('PLATFORM_JWT_SECRET', env('PLATFORM_JWT_SECRET', 'dev-only-insecure-secret-change-in-env'));

// AES-256 key (base64-encoded, 32 raw bytes) used to encrypt/decrypt real
// payment gateway API secrets — see includes/gateway_secrets.php. Rotating
// this makes every previously-stored gateway secret undecryptable, so back
// up and re-encrypt existing gateways before changing it in production.
define('GATEWAY_ENCRYPTION_KEY', env('GATEWAY_ENCRYPTION_KEY', null));

// Cache-busting query string appended to every JS asset (see
// includes/footer.php). Some hosts front the site with a CDN that caches
// static files independent of the browser — a viewer can hard-refresh or
// use Incognito and still get a stale app.js. Bump this string by hand
// after any JS deploy that must reach viewers immediately, so the query
// string changes and both the browser and any CDN in front of it treat it
// as a new file. Any value works as long as it changes; date-based is just
// easy to eyeball.
define('ASSET_VERSION', '20260913-1');

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

date_default_timezone_set('UTC');
