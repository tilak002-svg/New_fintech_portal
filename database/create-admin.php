<?php
/**
 * Create (or reset) a single admin account. Safe to run on a live/production
 * database — unlike seed.php, it touches only the one row for the given
 * email and never truncates anything.
 *
 * Usage (SSH / CLI):
 *   php database/create-admin.php admin@example.com "SomeStrongPassword123!" "Admin Name"
 *
 * If the email already exists, its password is reset and role/status are
 * force-set to admin/active. Otherwise a new admin user is created.
 */

require_once __DIR__ . '/../config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;
$name = $argv[3] ?? 'Admin';

if (!$email || !$password) {
    fwrite(STDERR, "Usage: php database/create-admin.php <email> <password> [name]\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email address.\n");
    exit(1);
}

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$pdo = db();
$hash = password_hash($password, PASSWORD_DEFAULT);

$initials = strtoupper(substr($name, 0, 1) . substr(strrchr(" " . $name, " "), 1, 1));

$existing = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$existing->execute([$email]);
$userId = $existing->fetchColumn();

if ($userId) {
    $pdo->prepare(
        'UPDATE users SET password_hash = ?, role = ?, status = ?, name = ?, must_change_password = 0 WHERE id = ?'
    )->execute([$hash, 'admin', 'active', $name, $userId]);
    echo "Updated existing user #{$userId} ({$email}) to an active admin and reset the password.\n";
} else {
    $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, status, avatar_initials) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$name, $email, $hash, 'admin', 'active', $initials]);
    echo "Created new admin user ({$email}), id #" . $pdo->lastInsertId() . ".\n";
}
