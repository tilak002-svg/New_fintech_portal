<?php
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/money.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$id = (int) ($input['id'] ?? 0);
$priorityRaw = $input['priority'] ?? null;

if ($id <= 0) {
    json_response(false, null, 'A gateway is required.', 422);
}
if (!is_numeric($priorityRaw) || (int) $priorityRaw < 0 || (int) $priorityRaw > 9999) {
    json_response(false, null, 'Priority must be a number between 0 and 9999. Lower numbers are tried first.', 422);
}

/**
 * Shared validation for every limit field: the key is required (send null
 * for "no limit"), and a non-null/non-empty value must be a valid
 * non-negative amount.
 */
function validate_limit_field(array $input, string $key, string $label): ?string
{
    if (!array_key_exists($key, $input)) {
        json_response(false, null, "{$key} is required — send null for no limit.", 422);
    }
    $raw = $input[$key];
    if ($raw === null || $raw === '') {
        return null;
    }
    $sanitized = sanitize_amount($raw);
    if ($sanitized === null || money_cmp($sanitized, '0.00') < 0) {
        json_response(false, null, "Enter a valid {$label}, or leave it blank for unlimited.", 422);
    }
    return $sanitized;
}

$dailyLimit = validate_limit_field($input, 'daily_limit_amount', 'daily limit amount');
$hourlyLimit = validate_limit_field($input, 'hourly_limit_amount', 'hourly limit amount');
$monthlyLimit = validate_limit_field($input, 'monthly_limit_amount', 'monthly limit amount');
$perTransactionLimit = validate_limit_field($input, 'per_transaction_limit_amount', 'per-transaction limit amount');
$minTicketSize = validate_limit_field($input, 'min_ticket_size', 'minimum ticket size');
$maxTicketSize = validate_limit_field($input, 'max_ticket_size', 'maximum ticket size');

if ($minTicketSize !== null && $maxTicketSize !== null && money_cmp($minTicketSize, $maxTicketSize) > 0) {
    json_response(false, null, 'The minimum ticket size cannot be greater than the maximum.', 422);
}

$payinEnabled = !array_key_exists('payin_enabled', $input) || (bool) $input['payin_enabled'];
$payoutEnabled = !array_key_exists('payout_enabled', $input) || (bool) $input['payout_enabled'];
$isMock = array_key_exists('is_mock', $input) && (bool) $input['is_mock'];

$pdo = db();
$stmt = $pdo->prepare('SELECT id, display_name FROM payment_gateways WHERE id = ?');
$stmt->execute([$id]);
$gateway = $stmt->fetch();

if (!$gateway) {
    json_response(false, null, 'Gateway not found.', 404);
}

$pdo->prepare(
    'UPDATE payment_gateways
     SET priority = ?, daily_limit_amount = ?, hourly_limit_amount = ?, monthly_limit_amount = ?, per_transaction_limit_amount = ?,
         min_ticket_size = ?, max_ticket_size = ?, payin_enabled = ?, payout_enabled = ?, is_mock = ?
     WHERE id = ?'
)->execute([
    (int) $priorityRaw, $dailyLimit, $hourlyLimit, $monthlyLimit, $perTransactionLimit,
    $minTicketSize, $maxTicketSize, $payinEnabled ? 1 : 0, $payoutEnabled ? 1 : 0, $isMock ? 1 : 0,
    $id,
]);

write_audit_log((int) $actor['id'], 'gateway_limits_updated', 'payment_gateway', $id, [
    'priority' => (int) $priorityRaw,
    'daily_limit_amount' => $dailyLimit,
    'hourly_limit_amount' => $hourlyLimit,
    'monthly_limit_amount' => $monthlyLimit,
    'per_transaction_limit_amount' => $perTransactionLimit,
    'min_ticket_size' => $minTicketSize,
    'max_ticket_size' => $maxTicketSize,
    'payin_enabled' => $payinEnabled,
    'payout_enabled' => $payoutEnabled,
    'is_mock' => $isMock,
]);

json_response(true, [
    'priority' => (int) $priorityRaw,
    'daily_limit_amount' => $dailyLimit,
    'hourly_limit_amount' => $hourlyLimit,
    'monthly_limit_amount' => $monthlyLimit,
    'per_transaction_limit_amount' => $perTransactionLimit,
    'min_ticket_size' => $minTicketSize,
    'max_ticket_size' => $maxTicketSize,
    'payin_enabled' => $payinEnabled,
    'payout_enabled' => $payoutEnabled,
    'is_mock' => $isMock,
], "{$gateway['display_name']}'s limits have been updated.");
