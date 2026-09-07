<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/payout_service.php';

$merchant = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(false, null, 'Method not allowed.', 405);
}

$reference = trim((string) ($_GET['reference'] ?? ''));
if ($reference === '') {
    json_response(false, null, 'reference is required.', 422);
}

$result = get_payout_status(db(), $merchant, $reference);

json_response($result['ok'], $result['data'], $result['message'], $result['status_code']);
