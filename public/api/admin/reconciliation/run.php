<?php
/**
 * Admin-triggered manual reconciliation run — lets an admin resolve stuck
 * pending transactions on demand instead of waiting for the next cron
 * tick (see bin/reconcile-pending.php, which calls the exact same
 * run_reconciliation() function).
 */
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/reconciliation.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$results = run_reconciliation(db());
$chargebackResults = reconcile_chargebacks(db());

write_audit_log((int) $actor['id'], 'reconciliation_run_manual', 'system', null, $results + ['chargebacks' => $chargebackResults]);

json_response(true, $results + ['chargebacks' => $chargebackResults], "Checked {$results['checked']}, resolved {$results['resolved']}. Chargebacks checked {$chargebackResults['checked']}, flagged {$chargebackResults['flagged']}.");
