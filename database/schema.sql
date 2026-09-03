-- Verapay database schema (MySQL/MariaDB, InnoDB, utf8mb4)

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'operator', 'admin') NOT NULL DEFAULT 'customer',
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    avatar_initials VARCHAR(4) NULL,
    gender ENUM('male', 'female', 'other') NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role (role),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (email, created_at),
    KEY idx_login_attempts_ip (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE wallets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    available_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    pending_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wallets_user (user_id),
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE business_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    legal_company_name VARCHAR(160) NULL,
    company_type VARCHAR(60) NULL,
    mobile_number VARCHAR(20) NULL,
    whatsapp_number VARCHAR(20) NULL,
    pan_number VARCHAR(10) NULL,
    gstin VARCHAR(15) NULL,
    office_address VARCHAR(255) NULL,
    identity_last4 CHAR(4) NULL,
    identity_hash VARCHAR(255) NULL,
    bank_account_holder VARCHAR(120) NULL,
    bank_account_last4 CHAR(4) NULL,
    bank_account_hash VARCHAR(255) NULL,
    bank_ifsc VARCHAR(11) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_business_profiles_user (user_id),
    CONSTRAINT fk_business_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_gateways (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    api_key_last4 CHAR(4) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    api_key_encrypted TEXT NULL,
    webhook_secret_encrypted TEXT NULL,
    public_key VARCHAR(190) NULL,
    -- RazorpayX payout source account number (the current account payouts are
    -- debited from) - distinct from public_key/api_key_encrypted, which for
    -- Razorpay already double as both Orders API and Payouts API auth. Only
    -- meaningful for provider = 'razorpay'; NULL means this gateway isn't
    -- configured for live payouts even if it does live pay-ins.
    payout_account_number VARCHAR(40) NULL,
    sandbox_mode TINYINT(1) NOT NULL DEFAULT 1,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    auto_paused_until DATETIME NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'inactive',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    daily_limit_amount DECIMAL(18,2) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payment_gateways_priority (status, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type ENUM('deposit', 'withdrawal') NOT NULL,
    method VARCHAR(60) NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    fee DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    status ENUM('pending', 'success', 'failed', 'cancelled', 'refunded') NOT NULL DEFAULT 'pending',
    reference VARCHAR(40) NOT NULL,
    destination VARCHAR(190) NULL,
    gateway_id INT UNSIGNED NULL,
    gateway_txn_id VARCHAR(120) NULL,
    idempotency_key VARCHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_transactions_reference (reference),
    UNIQUE KEY uq_transactions_idempotency_key (idempotency_key),
    UNIQUE KEY uq_transactions_gateway_txn (gateway_id, gateway_txn_id),
    KEY idx_transactions_user (user_id, created_at),
    KEY idx_transactions_type_status (type, status),
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_conversations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    subject VARCHAR(160) NOT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_conversations_user (user_id, updated_at),
    KEY idx_conversations_status (status),
    CONSTRAINT fk_conversations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE support_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    sender_role ENUM('customer', 'operator', 'admin') NOT NULL,
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    KEY idx_messages_conversation (conversation_id, id),
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES support_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(255) NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id, is_read, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-gateway, per-day usage counter. One row per (gateway, day), created
-- lazily and locked with SELECT ... FOR UPDATE at selection time so
-- concurrent requests reserving capacity against the same gateway on the
-- same day serialize instead of both reading a stale "remaining" value.
CREATE TABLE gateway_daily_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT UNSIGNED NOT NULL,
    usage_date DATE NOT NULL,
    used_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gateway_daily_usage (gateway_id, usage_date),
    CONSTRAINT fk_gateway_daily_usage_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Raw inbound gateway webhook deliveries. gateway_id + event_id is the
-- idempotency key: a re-delivered webhook for an event already recorded
-- here is a no-op instead of crediting the wallet a second time.
CREATE TABLE webhook_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT UNSIGNED NOT NULL,
    event_id VARCHAR(120) NOT NULL,
    gateway_txn_id VARCHAR(120) NULL,
    payload JSON NOT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('received', 'processed', 'ignored', 'failed') NOT NULL DEFAULT 'received',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uq_webhook_events_gateway_event (gateway_id, event_id),
    KEY idx_webhook_events_gateway_txn (gateway_txn_id),
    CONSTRAINT fk_webhook_events_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id INT UNSIGNED NULL,
    metadata JSON NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_actor (actor_id, created_at),
    KEY idx_audit_target (target_type, target_id),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE merchant_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    legal_company_name VARCHAR(160) NULL,
    company_type VARCHAR(60) NULL,
    mobile_number VARCHAR(20) NULL,
    whatsapp_number VARCHAR(20) NULL,
    pan_number VARCHAR(20) NULL,
    gstin VARCHAR(20) NULL,
    aadhar_number VARCHAR(20) NULL,
    office_address VARCHAR(255) NULL,
    kyc_locked TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_merchant_profiles_user (user_id),
    CONSTRAINT fk_merchant_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE settlement_banks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    account_holder VARCHAR(120) NOT NULL,
    account_number VARCHAR(40) NOT NULL,
    ifsc_code VARCHAR(20) NOT NULL,
    bank_name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_settlement_banks_user (user_id),
    CONSTRAINT fk_settlement_banks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE kyc_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    document_type ENUM(
        'aadhar_card', 'pan_card', 'gst_certificate', 'board_resolution',
        'certificate_of_incorporation', 'passport_photo', 'service_agreement'
    ) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_kyc_documents_user (user_id),
    UNIQUE KEY uq_kyc_documents_user_type (user_id, document_type),
    CONSTRAINT fk_kyc_documents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE platform_api_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    client_key VARCHAR(40) NOT NULL,
    secret_key_hash VARCHAR(255) NOT NULL,
    secret_key_last4 VARCHAR(4) NOT NULL,
    bearer_token TEXT NULL,
    bearer_token_generated_at DATETIME NULL,
    primary_whitelist_ip VARCHAR(45) NULL,
    payout_callback_url VARCHAR(255) NULL,
    payin_callback_url VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_platform_api_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-customer API credentials for calling Verapay's own API programmatically
-- (deposits/withdrawals/wallet/etc. on that customer's own behalf), distinct
-- from platform_api_settings above (a single platform-wide credential set,
-- superseded by this — kept in place rather than dropped, but no longer
-- surfaced in the admin UI). One row per user: client_key/secret/bearer
-- token are self-service (customer-owned), matching how Stripe/Razorpay
-- issue one key pair per merchant rather than one shared platform key.
CREATE TABLE customer_api_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    client_key VARCHAR(40) NOT NULL,
    secret_key_hash VARCHAR(255) NOT NULL,
    secret_key_last4 VARCHAR(4) NOT NULL,
    bearer_token TEXT NULL,
    bearer_token_generated_at DATETIME NULL,
    payout_callback_url VARCHAR(255) NULL,
    payin_callback_url VARCHAR(255) NULL,
    -- Signs outbound deliveries to payin_callback_url/payout_callback_url
    -- (X-Verapay-Signature) so the customer's receiver can verify a webhook
    -- really came from Verapay. Deliberately separate from secret_key_hash
    -- above: that one is one-way (a login-style credential checked with
    -- password_verify()) and can never be turned back into something usable
    -- for HMAC signing. Encrypted at rest the same way gateway secrets are
    -- (see includes/gateway_secrets.php) since, unlike secret_key, this one
    -- genuinely needs to be decrypted server-side on every outbound delivery.
    webhook_signing_secret_encrypted TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_api_credentials_user (user_id),
    UNIQUE KEY uq_customer_api_credentials_client_key (client_key),
    CONSTRAINT fk_customer_api_credentials_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deliberately admin-managed, not customer self-service: if a customer's
-- own Verapay login were compromised, letting them also edit their own IP
-- whitelist would let an attacker open API access from a new location
-- with nothing else required. Requiring an admin action here means account
-- takeover alone can't silently expand where a stolen bearer token works.
CREATE TABLE customer_whitelisted_ips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    added_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_whitelisted_ips (user_id, ip_address),
    CONSTRAINT fk_customer_whitelisted_ips_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_whitelisted_ips_admin FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE platform_whitelisted_ips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_platform_whitelisted_ips_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
