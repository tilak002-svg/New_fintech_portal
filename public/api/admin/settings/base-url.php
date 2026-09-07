<?php
/**
 * Admin-editable override for the platform's API Base URL — see
 * includes/functions.php::platform_api_base_url(). GET returns the
 * current effective value (override or the APP_URL-derived default);
 * POST sets/clears the override.
 */
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$actor = api_guard(['admin']);

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT api_base_url, updated_at FROM platform_settings WHERE id = 1');
    $row = $stmt->fetch();

    json_response(true, [
        'api_base_url' => platform_api_base_url(),
        'is_override' => !empty($row['api_base_url']),
        'default_url' => rtrim(APP_URL, '/') . '/api/v1',
        'updated_at' => $row['updated_at'] ?? null,
    ], 'ok');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$url = trim((string) ($input['api_base_url'] ?? ''));

// Empty input clears the override, reverting to the APP_URL-derived
// default — a deliberate, explicit way back rather than a dead end once
// an admin has set one.
if ($url !== '') {
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        json_response(false, null, 'Enter a valid http:// or https:// URL.', 422, 'VALIDATION_ERROR');
    }
    if (mb_strlen($url) > 255) {
        json_response(false, null, 'URL is too long (max 255 characters).', 422, 'VALIDATION_ERROR');
    }
    $url = rtrim($url, '/');
}

$oldStmt = $pdo->query('SELECT api_base_url FROM platform_settings WHERE id = 1');
$oldUrl = $oldStmt->fetchColumn();

$pdo->prepare(
    'INSERT INTO platform_settings (id, api_base_url) VALUES (1, ?)
     ON DUPLICATE KEY UPDATE api_base_url = VALUES(api_base_url)'
)->execute([$url ?: null]);

write_audit_log((int) $actor['id'], 'platform_base_url_changed', 'system', null, [
    'old_url' => $oldUrl ?: null,
    'new_url' => $url ?: null,
]);

json_response(true, [
    'api_base_url' => $url ?: (rtrim(APP_URL, '/') . '/api/v1'),
    'is_override' => $url !== '',
], $url !== '' ? 'Platform API Base URL updated.' : 'Reverted to the default API Base URL.');
