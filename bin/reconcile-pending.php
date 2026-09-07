<?php
/**
 * CLI entry point — meant to be invoked by an OS-level cron/scheduled
 * task every few minutes. Not wired up automatically anywhere; see
 * README.md for the exact crontab/Task Scheduler line to add.
 *
 * Usage: php bin/reconcile-pending.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reconciliation.php';

$results = run_reconciliation(db());
$chargebackResults = reconcile_chargebacks(db());

echo '[' . gmdate('c') . '] reconciliation: ' . json_encode($results) . PHP_EOL;
echo '[' . gmdate('c') . '] chargeback reconciliation: ' . json_encode($chargebackResults) . PHP_EOL;
exit((int) ($results['errors'] > 0));
