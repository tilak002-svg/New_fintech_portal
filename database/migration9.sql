-- Phase 1 of the merchant PayIn/PayOut pivot (see the approved plan). Adds
-- end-customer identity fields for a PayIn and beneficiary bank fields for
-- a PayOut to `transactions`, plus the merchant's own order reference and
-- its uniqueness constraint, and the hosted-checkout session table that
-- lets the merchant-facing API hand back a Verapay-hosted payment_url
-- instead of raw provider checkout fields. transactions.type keeps its
-- existing 'deposit'/'withdrawal' enum values — see
-- includes/functions.php::transaction_type_public_name() for where
-- 'payin'/'payout' vocabulary is applied instead of widening a live-data
-- column.

ALTER TABLE transactions
    ADD COLUMN merchant_order_id VARCHAR(120) NULL AFTER idempotency_key,
    ADD COLUMN end_customer_name VARCHAR(120) NULL AFTER merchant_order_id,
    ADD COLUMN end_customer_email VARCHAR(190) NULL AFTER end_customer_name,
    ADD COLUMN end_customer_phone VARCHAR(20) NULL AFTER end_customer_email,
    ADD COLUMN beneficiary_name VARCHAR(120) NULL AFTER end_customer_phone,
    ADD COLUMN beneficiary_account_number VARCHAR(40) NULL AFTER beneficiary_name,
    ADD COLUMN beneficiary_ifsc VARCHAR(20) NULL AFTER beneficiary_account_number,
    ADD COLUMN beneficiary_bank_name VARCHAR(120) NULL AFTER beneficiary_ifsc,
    ADD COLUMN beneficiary_phone VARCHAR(20) NULL AFTER beneficiary_bank_name,
    ADD COLUMN beneficiary_address VARCHAR(255) NULL AFTER beneficiary_phone,
    ADD UNIQUE KEY uq_transactions_merchant_order (user_id, merchant_order_id);

CREATE TABLE IF NOT EXISTS payment_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_token CHAR(64) NOT NULL,
    transaction_id INT UNSIGNED NOT NULL,
    gateway_id INT UNSIGNED NOT NULL,
    checkout_payload JSON NOT NULL,
    return_url VARCHAR(255) NULL,
    status ENUM('created', 'completed', 'expired', 'cancelled') NOT NULL DEFAULT 'created',
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_sessions_token (session_token),
    KEY idx_payment_sessions_transaction (transaction_id),
    CONSTRAINT fk_payment_sessions_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_sessions_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
