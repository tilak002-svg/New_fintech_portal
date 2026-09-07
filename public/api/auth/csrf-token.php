<?php
/**
 * Public. Returns the current session's CSRF token, minting one if this
 * is a fresh/anonymous session. Used by the login page to silently
 * recover from a stale embedded token
 * (e.g. the tab was left open past SESSION_LIFETIME_MINUTES) instead of
 * failing with "Your session has expired" until the user manually
 * refreshes the page.
 */
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

json_response(true, ['csrf_token' => csrf_token()], 'ok');
