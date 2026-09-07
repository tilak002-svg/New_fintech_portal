<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$user = api_guard();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$current = (string) ($input['current_password'] ?? '');
$new = (string) ($input['new_password'] ?? '');
$confirm = (string) ($input['confirm_password'] ?? '');

$pdo = db();
$stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$hash = $stmt->fetchColumn();

if (!password_verify($current, $hash)) {
    json_response(false, null, 'Your current password is incorrect.', 422);
}
if (mb_strlen($new) < 10) {
    json_response(false, null, 'New password must be at least 10 characters.', 422);
}
if ($new !== $confirm) {
    json_response(false, null, 'New password and confirmation do not match.', 422);
}
if (password_verify($new, $hash)) {
    json_response(false, null, 'New password must be different from your current password.', 422);
}

$pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
write_audit_log((int) $user['id'], 'password_changed', 'user', (int) $user['id'], []);

json_response(true, null, 'Password updated.');
