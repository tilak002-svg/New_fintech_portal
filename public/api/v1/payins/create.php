<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/payin_service.php';

$merchant = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

// Generous on purpose (real payment traffic, including retries from a
// merchant's own backend, must never be blocked) — this exists to catch a
// runaway integration loop, not to throttle legitimate volume.
enforce_rate_limit("payin_create:merchant:{$merchant['id']}", 120, 60, 'Too many PayIn requests. Please slow down and try again shortly.');

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$result = create_payin(db(), $merchant, is_array($input) ? $input : []);

json_response($result['ok'], $result['data'], $result['message'], $result['status_code'], $result['ok'] ? null : payin_payout_error_code($result['status_code']));
