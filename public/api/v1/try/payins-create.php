<?php
/**
 * Backs the "Try it" tester on pages/api-docs.php ONLY — never listed in
 * the documented endpoint table. Identical to the real
 * public/api/v1/payins/create.php except sandboxOnly:true is forced, so a
 * test request can never reach a live gateway (see
 * includes/gateway_selector.php's $sandboxOnly param).
 */

require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/payin_service.php';

$merchant = api_guard(['customer']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$result = create_payin(db(), $merchant, is_array($input) ? $input : [], sandboxOnly: true);

json_response($result['ok'], $result['data'], $result['message'], $result['status_code']);
