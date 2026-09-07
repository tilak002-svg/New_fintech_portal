<?php
/**
 * Admin-triggered manual run of the outbound customer-webhook retry queue
 * — lets an admin push a due retry through immediately instead of waiting
 * for the next cron tick (see bin/process-webhook-retries.php, which
 * calls the exact same process_webhook_retry_queue() function).
 */
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';
require_once __DIR__ . '/../../../../includes/customer_webhooks.php';

$actor = api_guard(['admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, null, 'Method not allowed.', 405);
}

$results = process_webhook_retry_queue(db());

write_audit_log((int) $actor['id'], 'webhook_retry_run_manual', 'system', null, $results);

json_response(true, $results, "Processed {$results['processed']} due deliveries.");
