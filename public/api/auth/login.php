<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

bootstrap_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

// CSRF: the login form itself is the only place the token can be issued
// before a session exists, so we accept the token minted for this
// anonymous session rather than calling verify_csrf() (which requires
// an authenticated context for later requests).
$sentToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
    json_response(false, null, 'Your session has expired. Please refresh and try again.', 419, 'CSRF_MISMATCH');
}

$email = trim(strtolower((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(false, null, 'Enter a valid email and password.', 422, 'VALIDATION_ERROR');
}

if (login_is_locked_out($email, $ip)) {
    json_response(false, null, 'Too many failed attempts. Try again in a few minutes.', 429, 'RATE_LIMITED');
}

$stmt = db()->prepare('SELECT id, name, email, password_hash, role, status FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    record_login_attempt($email, $ip, false);
    json_response(false, null, 'Incorrect email or password.', 401, 'INVALID_CREDENTIALS');
}

if ($user['status'] !== 'active') {
    record_login_attempt($email, $ip, false);
    json_response(false, null, 'This account has been suspended. Contact support for assistance.', 403, 'ACCOUNT_SUSPENDED');
}

record_login_attempt($email, $ip, true);

// Regenerate the session id on privilege change to prevent session fixation.
session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

write_audit_log($user['id'], 'login', 'user', $user['id'], ['email' => $user['email']]);

json_response(true, ['redirect' => '/dashboard'], 'Signed in successfully.');
