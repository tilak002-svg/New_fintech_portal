<?php
/**
 * Router script for PHP's built-in development server ONLY (see
 * docker/php.Dockerfile and README.md's non-Docker `php -S` instructions).
 * Apache (.htaccess) and Nginx already resolve a request to a real file
 * before ever invoking index.php via their own rewrite rules — this file
 * exists purely to make the built-in server behave the same way, since
 * without a router script it 404s directly (never reaching index.php) for
 * any path whose directory prefix happens to exist on disk, e.g.
 * /api/v1/balance (no .php) would 404 immediately because public/api/v1/
 * is a real directory, even though public/api/v1/balance is not a real
 * file — see the extension-less API alias in index.php this is required
 * to actually reach.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // let the built-in server serve the real file as-is
}

require __DIR__ . '/index.php';
