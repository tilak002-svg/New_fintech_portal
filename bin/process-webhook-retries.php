<?php
/**
 * CLI entry point — meant to be invoked by an OS-level cron/scheduled
 * task every minute or two. Not wired up automatically anywhere; see
 * README.md for the exact crontab/Task Scheduler line to add.
 *
 * Usage: php bin/process-webhook-retries.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_webhooks.php';

$results = process_webhook_retry_queue(db());

echo '[' . gmdate('c') . '] webhook retries: ' . json_encode($results) . PHP_EOL;
exit(0);
