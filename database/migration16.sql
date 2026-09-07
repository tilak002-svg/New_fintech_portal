-- Outbound customer webhook delivery queue with retry/backoff — replaces
-- the previous fire-once-and-log behavior in includes/customer_webhooks.php.
-- One row per delivery ATTEMPT SERIES for one transaction event (not one
-- row per attempt); attempts/last_http_status/last_error describe the
-- most recent try.
CREATE TABLE IF NOT EXISTS customer_webhook_deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    event VARCHAR(60) NOT NULL,
    url VARCHAR(255) NOT NULL,
    payload JSON NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    status ENUM('pending', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
    last_http_status SMALLINT NULL,
    last_error VARCHAR(255) NULL,
    next_attempt_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cwd_status_next (status, next_attempt_at),
    KEY idx_cwd_transaction (transaction_id),
    CONSTRAINT fk_cwd_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_cwd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reconciliation visibility for pending transactions — bin/reconcile-pending.php.
ALTER TABLE transactions
    ADD COLUMN last_reconciled_at DATETIME NULL AFTER updated_at,
    ADD COLUMN reconciliation_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_reconciled_at;
