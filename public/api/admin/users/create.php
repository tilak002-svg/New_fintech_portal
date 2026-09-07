<?php

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$name = trim((string) ($input['name'] ?? ''));
$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$gender = trim((string) ($input['gender'] ?? ''));

if ($name === '') {
    json_response(false, null, 'Customer name is required.', 422);
}

if (mb_strlen($name) > 120) {
    json_response(false, null, 'Customer name is too long.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(false, null, 'Please enter a valid email address.', 422);
}

if (mb_strlen($email) > 190) {
    json_response(false, null, 'Email address is too long.', 422);
}

if (strlen($password) < 8) {
    json_response(false, null, 'Password must contain at least 8 characters.', 422);
}

if (strlen($password) > 72) {
    json_response(false, null, 'Password is too long.', 422);
}

if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
    json_response(false, null, 'Invalid gender selected.', 422);
}

$pdo = db();

$existingStmt = $pdo->prepare(
    'SELECT id FROM users WHERE email = ? LIMIT 1'
);

$existingStmt->execute([$email]);

if ($existingStmt->fetch()) {
    json_response(
        false,
        null,
        'A user with this email address already exists.',
        409
    );
}

$avatarInitials = '';

$nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);

if (count($nameParts) >= 2) {
    $avatarInitials =
        mb_substr($nameParts[0], 0, 1) .
        mb_substr($nameParts[count($nameParts) - 1], 0, 1);
} elseif (count($nameParts) === 1) {
    $avatarInitials = mb_substr($nameParts[0], 0, 2);
}

$avatarInitials = mb_strtoupper($avatarInitials);

$passwordHash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

try {
    $pdo->beginTransaction();

    $userStmt = $pdo->prepare(
        'INSERT INTO users
            (name, email, password_hash, role, status, must_change_password, avatar_initials, gender)
         VALUES
            (?, ?, ?, "customer", "active", 0, ?, ?)'
    );

    $userStmt->execute([
        $name,
        $email,
        $passwordHash,
        $avatarInitials ?: null,
        $gender !== '' ? $gender : null
    ]);

    $customerId = (int) $pdo->lastInsertId();

    $walletStmt = $pdo->prepare(
        'INSERT INTO wallets
            (user_id, available_balance, pending_balance, currency)
         VALUES
            (?, 0.00, 0.00, "INR")'
    );

    $walletStmt->execute([$customerId]);

    write_audit_log(
        (int) $actor['id'],
        'user_created',
        'user',
        $customerId,
        [
            'target_email' => $email,
            'target_role' => 'customer'
        ]
    );

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ((int) $e->getCode() === 23000) {
        json_response(
            false,
            null,
            'A user with this email address already exists.',
            409
        );
    }

    error_log(
        'Admin customer creation failed: ' . $e->getMessage()
    );

    json_response(
        false,
        null,
        'Unable to create the customer right now. Please try again.',
        500
    );
}

json_response(
    true,
    [
        'user' => [
            'id' => $customerId,
            'name' => $name,
            'email' => $email,
            'role' => 'customer',
            'status' => 'active',
            'avatar_initials' => $avatarInitials
        ]
    ],
    "{$name} has been added as a customer."
);