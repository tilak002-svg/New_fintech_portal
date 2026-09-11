<?php
/**
 * Authentication / authorization middleware.
 *
 * Every protected page and every API endpoint must call require_auth()
 * (and require_role() where relevant) before doing anything else. Each
 * call independently re-checks the session AND re-queries the user's
 * current status in the database — this is what makes admin-side
 * suspension take effect on the very next request from the affected
 * user, without any separate token-revocation table.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

function bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('verapay_session');
    session_start();

    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME_MINUTES * 60) {
        // Idle-expired. Clear the data and issue a brand new session id so
        // this request continues with a clean, properly-cookied session —
        // deliberately NOT destroy_session() here, since that sends a
        // cookie-*deletion* header (Max-Age=0) and PHP only sends a fresh
        // Set-Cookie when the session id actually changes; reusing the
        // same (already-destroyed) id would leave the browser with no
        // session cookie at all for its next request. Without this,
        // any later call to bootstrap_session() in the SAME request
        // (e.g. rendering pages/auth/login.php right after redirecting an
        // idle session here) would also short-circuit at the guard above
        // and never get a fresh csrf_token, breaking that page's login
        // form with a CSRF mismatch on submit.
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();

    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function is_json_request(): bool
{
    return str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/');
}

function deny_unauthenticated(string $message = 'Please sign in to continue.'): never
{
    destroy_session();
    if (is_json_request()) {
        json_response(false, null, $message, 401);
    }
    header('Location: /login');
    exit;
}

function deny_suspended(): never
{
    destroy_session();
    $message = 'Your account has been suspended. Contact support for assistance.';
    if (is_json_request()) {
        json_response(false, null, $message, 403);
    }
    header('Location: /suspended');
    exit;
}

/**
 * Validates the session and re-checks live account status. Returns the
 * authenticated user row on success; never returns on failure.
 */
function require_auth(): array
{
    bootstrap_session();

    if (empty($_SESSION['user_id'])) {
        deny_unauthenticated();
    }

   $stmt = db()->prepare('SELECT id, name, email, role, status, must_change_password, avatar_initials, gender, created_at FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        deny_unauthenticated();
    }

    if ($user['status'] !== 'active') {
        deny_suspended();
    }

    return $user;
}

function require_role(array $user, string ...$roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        if (is_json_request()) {
            json_response(false, null, 'You do not have permission to perform this action.', 403);
        }
        http_response_code(403);
        echo 'You do not have permission to view this page.';
        exit;
    }
}

function csrf_token(): string
{
    bootstrap_session();
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return;
    }

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($sent === '' || !hash_equals($expected, $sent)) {
        if (is_json_request()) {
            json_response(false, null, 'Your session has expired. Please refresh and try again.', 419);
        }
        http_response_code(419);
        echo 'Your session has expired. Please refresh and try again.';
        exit;
    }
}

/**
 * Verifies a customer's own API bearer token (Settings → API access) and
 * returns the user it belongs to — never returns on failure. Bearer auth
 * is a distinct trust path from the browser session: no CSRF check
 * (a stolen bearer token isn't a CSRF vector — nothing makes a browser
 * attach it automatically the way it does a cookie), but layered with two
 * checks the session path doesn't need: the token must exactly match what's
 * currently stored (so regenerating a token immediately invalidates the
 * old one, even though the old JWT itself wouldn't otherwise expire for
 * up to a year), and the caller's IP must have an APPROVED row on that
 * customer's whitelist — customer-requested, admin-approved (see
 * customer_whitelisted_ips in schema.sql) — deliberately fails closed if
 * none is approved yet, whether because none was ever requested or
 * because a request is still pending/was rejected.
 */
function authenticate_via_bearer_token(string $token): array
{
    $payload = jwt_decode_verify($token, PLATFORM_JWT_SECRET);
    if ($payload === null || empty($payload['sub']) || !ctype_digit((string) $payload['sub'])) {
        json_response(false, null, 'Invalid or expired API token.', 401, 'INVALID_TOKEN');
    }
    $userId = (int) $payload['sub'];

    $credStmt = db()->prepare('SELECT bearer_token FROM customer_api_credentials WHERE user_id = ?');
    $credStmt->execute([$userId]);
    $stored = $credStmt->fetchColumn();

    if (!$stored || !hash_equals($stored, $token)) {
        // Either no credentials were ever provisioned for this user, or
        // this token was superseded by a later regeneration.
        json_response(false, null, 'This API token has been revoked. Generate a new one.', 401, 'TOKEN_REVOKED');
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ipStmt = db()->prepare("SELECT 1 FROM customer_whitelisted_ips WHERE user_id = ? AND ip_address = ? AND status = 'approved'");
    $ipStmt->execute([$userId, $ip]);
    if (!$ipStmt->fetchColumn()) {
        write_audit_log($userId, 'api_request_blocked_ip', 'user', $userId, ['ip' => $ip]);
        // Includes the caller's own IP in `data` (never in prior versions of
        // this message) so a legitimate customer's own tooling — e.g.
        // pages/key-verification.php — can show them exactly what to ask
        // support to whitelist, without this being a new attack surface:
        // it only ever echoes back the requester's own already-known address.
        json_response(false, ['ip' => $ip], 'This request\'s IP address is not whitelisted for this account. Contact support to have it added.', 403, 'IP_NOT_WHITELISTED');
    }

    $userStmt = db()->prepare('SELECT id, name, email, role, status, avatar_initials, gender, created_at FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user) {
        json_response(false, null, 'Invalid or expired API token.', 401, 'INVALID_TOKEN');
    }
    if ($user['status'] !== 'active') {
        json_response(false, null, 'This account has been suspended.', 403, 'ACCOUNT_SUSPENDED');
    }

    // Marks this request as genuine bearer-token API traffic, so
    // json_response() (includes/functions.php) knows to write an api_logs
    // row once the final status code is known. Never set for the
    // session+CSRF path — that's ordinary dashboard usage, not "API" traffic.
    $GLOBALS['__api_log_user_id'] = $userId;

    return $user;
}

/** Extracts a Bearer token from the Authorization header, checking every key PHP/Apache might expose it under. */
function bearer_token_from_request(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? null) : null);

    if (!$header || !str_starts_with($header, 'Bearer ')) {
        return null;
    }
    return trim(substr($header, 7));
}

/**
 * Call at the top of every API endpoint. Combines auth + CSRF + optional
 * role check for the normal browser-session path. Also accepts a
 * customer's own API bearer token as an alternate identity — same role
 * checks apply either way, so an admin-only endpoint stays admin-only
 * regardless of which path authenticated the caller.
 */
function api_guard(array $roles = []): array
{
    $token = bearer_token_from_request();
    if ($token !== null) {
        $user = authenticate_via_bearer_token($token);
        if (!empty($roles)) {
            require_role($user, ...$roles);
        }
        return $user;
    }

    $user = require_auth();
    verify_csrf();
    if (!empty($roles)) {
        require_role($user, ...$roles);
    }
    return $user;
}

// ---- Login rate limiting ----

function login_is_locked_out(string $email, string $ip): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (email = ? OR ip_address = ?) AND succeeded = 0
           AND created_at > (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$email, $ip, LOGIN_LOCKOUT_MINUTES]);
    return (int) $stmt->fetchColumn() >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(string $email, string $ip, bool $succeeded): void
{
    $stmt = db()->prepare('INSERT INTO login_attempts (email, ip_address, succeeded) VALUES (?, ?, ?)');
    $stmt->execute([$email, $ip, $succeeded ? 1 : 0]);
}

function write_audit_log(?int $actorId, string $action, string $targetType, ?int $targetId, array $metadata = []): void
{
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (actor_id, action, target_type, target_id, metadata, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$actorId, $action, $targetType, $targetId, json_encode($metadata), $_SERVER['REMOTE_ADDR'] ?? null]);
}
