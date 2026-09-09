-- Verapay database schema (MySQL/MariaDB, InnoDB, utf8mb4)

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('customer', 'operator', 'admin') NOT NULL DEFAULT 'customer',
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    -- Forces a customer created by admin (with an admin-chosen temporary
    -- password) to set their own password before reaching anything else.
    -- Enforced in public/index.php's routing; cleared by
    -- public/api/settings/change-password.php on a successful change.
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
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
    -- Outstanding chargeback liability that exceeded available_balance at
    -- the time it was applied — see database/migration19.sql and
    -- includes/chargeback_service.php.
    receivable_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
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
    -- Selection eligibility, not just capacity: a gateway missing either
    -- flag is skipped for that direction entirely (see
    -- includes/gateway_selector.php), independent of whether it has
    -- capacity remaining.
    payin_enabled TINYINT(1) NOT NULL DEFAULT 1,
    payout_enabled TINYINT(1) NOT NULL DEFAULT 1,
    -- Explicit, admin-visible opt-in for the local instant-success
    -- simulated path (create_payin()/create_payout()'s no-live-gateway
    -- branch) — the ONLY condition that path may trigger under. A gateway
    -- claiming a real provider but missing/invalid credentials is never
    -- silently mocked; it's simply ineligible for selection.
    is_mock TINYINT(1) NOT NULL DEFAULT 0,
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    daily_limit_amount DECIMAL(18,2) NULL,
    hourly_limit_amount DECIMAL(18,2) NULL,
    monthly_limit_amount DECIMAL(18,2) NULL,
    per_transaction_limit_amount DECIMAL(18,2) NULL,
    -- Ticket-size band — distinct from per_transaction_limit_amount (a hard
    -- ceiling only). NULL means no floor/ceiling on that side.
    min_ticket_size DECIMAL(18,2) NULL,
    max_ticket_size DECIMAL(18,2) NULL,
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
    -- Merchant PayIn/PayOut API fields (see includes/payin_service.php,
    -- includes/payout_service.php). NULL for every legacy deposit/withdrawal
    -- row created via the browser-session flow. merchant_order_id is the
    -- merchant's OWN order reference (distinct from `reference`/
    -- `idempotency_key`, which are Verapay-internal) — see
    -- uq_transactions_merchant_order below. end_customer_* is populated for
    -- a PayIn (who paid); beneficiary_* is populated for a PayOut (who was
    -- paid) and is a per-request snapshot, deliberately not sourced from
    -- settlement_banks (which is the merchant's OWN bank, reserved for a
    -- future Settlements feature — see docs/plan for the pivot).
    merchant_order_id VARCHAR(120) NULL,
    end_customer_name VARCHAR(120) NULL,
    end_customer_email VARCHAR(190) NULL,
    end_customer_phone VARCHAR(20) NULL,
    beneficiary_name VARCHAR(120) NULL,
    beneficiary_account_number VARCHAR(40) NULL,
    beneficiary_ifsc VARCHAR(20) NULL,
    beneficiary_bank_name VARCHAR(120) NULL,
    beneficiary_phone VARCHAR(20) NULL,
    beneficiary_address VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Reconciliation visibility for pending transactions — see
    -- includes/reconciliation.php / bin/reconcile-pending.php.
    last_reconciled_at DATETIME NULL,
    reconciliation_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_transactions_reference (reference),
    UNIQUE KEY uq_transactions_idempotency_key (idempotency_key),
    UNIQUE KEY uq_transactions_gateway_txn (gateway_id, gateway_txn_id),
    -- NULL repeats freely under a MySQL UNIQUE index, so this only takes
    -- effect for merchant-API-created rows (merchant_order_id NOT NULL) —
    -- a no-op for every legacy deposit/withdrawal row.
    UNIQUE KEY uq_transactions_merchant_order (user_id, merchant_order_id),
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

-- One row per gateway per hour (usage_hour = the hour bucket's start).
-- Same locking pattern as gateway_daily_usage.
CREATE TABLE gateway_hourly_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT UNSIGNED NOT NULL,
    usage_hour DATETIME NOT NULL,
    used_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gateway_hourly_usage (gateway_id, usage_hour),
    CONSTRAINT fk_gateway_hourly_usage_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per gateway per calendar month (usage_month = 'YYYY-MM').
CREATE TABLE gateway_monthly_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT UNSIGNED NOT NULL,
    usage_month CHAR(7) NOT NULL,
    used_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gateway_monthly_usage (gateway_id, usage_month),
    CONSTRAINT fk_gateway_monthly_usage_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
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

-- Raw API request log — distinct from audit_logs (admin/customer ACTIONS).
-- Written only for bearer-token-authenticated requests — see
-- includes/auth.php::authenticate_via_bearer_token() and
-- includes/functions.php::json_response().
CREATE TABLE api_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    method VARCHAR(10) NOT NULL,
    endpoint VARCHAR(190) NOT NULL,
    http_status SMALLINT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_api_logs_user (user_id, created_at),
    KEY idx_api_logs_created (created_at),
    CONSTRAINT fk_api_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    -- Set only from a client-confirmed successful run of the Key/API
    -- Verification page (pages/key-verification.php) — informational UI
    -- state, not itself a security check.
    last_verified_at DATETIME NULL,
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

-- Hosted-checkout session for the merchant PayIn API (includes/payin_service.php).
-- create_gateway_order() returns a provider-shaped payload (Razorpay
-- order_id/key_id, Cashfree payment_session_id) that must never reach a
-- merchant's own API response — that would mean the merchant is integrating
-- the underlying gateway directly, which this platform exists to prevent.
-- Instead that payload is stashed here, keyed by an unguessable
-- session_token, and the merchant is handed a Verapay-hosted payment_url
-- (see pages/pay-checkout.php) that renders it out to their end-customer.
-- Single admin-editable override for the API Base URL shown to every
-- customer (Settings/API Access, API documentation). NULL api_base_url
-- means "no override configured yet" — every reader falls back to
-- bare APP_URL (see includes/functions.php's platform_api_base_url()).
CREATE TABLE platform_settings (
    id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
    api_base_url VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_platform_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Outbound customer webhook delivery queue with retry/backoff — see
-- includes/customer_webhooks.php. One row per delivery ATTEMPT SERIES for
-- one transaction event; attempts/last_http_status/last_error describe
-- the most recent try.
CREATE TABLE customer_webhook_deliveries (
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

-- Generic rate-limit counter, shared by every endpoint that needs one
-- (password reset, API token exchange, PayIn/PayOut creation, ...) rather
-- than a dedicated table per endpoint.
CREATE TABLE rate_limit_hits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate_limit_lookup (rate_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Forgot/reset-password flow. Tokens are single-use, short-lived, and
-- stored only as a hash (same principle as customer_api_credentials'
-- secret_key_hash) — the raw token exists only in the emailed/returned
-- link, never in the database.
CREATE TABLE password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_password_resets_user (user_id),
    KEY idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE payment_sessions (
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

-- Chargeback / dispute lifecycle — see database/migration19.sql for the
-- full rationale (immutable ledger, event-level idempotency, out-of-order
-- protection) and includes/chargeback_service.php for the state machine.
CREATE TABLE wallet_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    entry_type VARCHAR(40) NOT NULL,
    reference_type VARCHAR(40) NOT NULL,
    reference_id INT UNSIGNED NOT NULL,
    amount DECIMAL(18,2) NOT NULL,
    available_balance_after DECIMAL(18,2) NOT NULL,
    receivable_balance_after DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_wallet_ledger_user (user_id, created_at),
    KEY idx_wallet_ledger_reference (reference_type, reference_id),
    CONSTRAINT fk_wallet_ledger_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chargebacks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    gateway_id INT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    gateway_chargeback_id VARCHAR(120) NOT NULL,
    gateway_reference VARCHAR(120) NULL,
    amount DECIMAL(18,2) NOT NULL,
    fee DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    reason_code VARCHAR(60) NULL,
    reason VARCHAR(255) NULL,
    status ENUM('open', 'pending', 'won', 'lost', 'reversed', 'cancelled') NOT NULL DEFAULT 'open',
    provider_status VARCHAR(60) NULL,
    financial_impact_applied_at DATETIME NULL,
    last_event_at DATETIME NULL,
    initiated_at DATETIME NULL,
    due_at DATETIME NULL,
    resolved_at DATETIME NULL,
    resolution VARCHAR(255) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chargebacks_provider_dispute (provider, gateway_chargeback_id),
    KEY idx_chargebacks_transaction (transaction_id),
    KEY idx_chargebacks_user (user_id, status),
    KEY idx_chargebacks_status (status),
    CONSTRAINT fk_chargebacks_transaction FOREIGN KEY (transaction_id) REFERENCES transactions(id),
    CONSTRAINT fk_chargebacks_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_chargebacks_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE chargeback_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chargeback_id INT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    gateway_chargeback_id VARCHAR(120) NOT NULL,
    gateway_event_id VARCHAR(150) NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    provider_status VARCHAR(60) NULL,
    normalized_status VARCHAR(20) NULL,
    applied TINYINT(1) NOT NULL DEFAULT 0,
    skip_reason VARCHAR(120) NULL,
    payload JSON NULL,
    occurred_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chargeback_events_event (provider, gateway_event_id),
    KEY idx_chargeback_events_chargeback (chargeback_id),
    CONSTRAINT fk_chargeback_events_chargeback FOREIGN KEY (chargeback_id) REFERENCES chargebacks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
