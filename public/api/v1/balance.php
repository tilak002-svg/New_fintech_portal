<?php
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/functions.php';

$merchant = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

// This is Verapay's settlement ledger balance — funds collected via PayIns
// minus PayOuts already disbursed — not a "wallet the merchant deposits
// into" in the old per-account sense. See includes/wallets and the pivot's
// Key Decision 2.
$stmt = db()->prepare('SELECT available_balance, pending_balance, currency, updated_at FROM wallets WHERE user_id = ?');
$stmt->execute([$merchant['id']]);
$wallet = $stmt->fetch() ?: ['available_balance' => '0.00', 'pending_balance' => '0.00', 'currency' => 'INR', 'updated_at' => null];

json_response(true, $wallet, 'ok');
