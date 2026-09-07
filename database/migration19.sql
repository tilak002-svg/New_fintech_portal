-- Chargeback / dispute lifecycle — a real PayIn can be disputed by the end
-- customer's bank AFTER it has already settled SUCCESS. This is deliberately
-- NOT modeled as a transaction status change (transactions.status stays
-- SUCCESS forever, historically accurate — see includes/chargeback_service.php)
-- but as its own lifecycle, financially applied through an immutable ledger
-- rather than ever editing a past transaction or ledger row.

-- Merchant's own outstanding liability when a chargeback debit exceeds their
-- available_balance — the "chargeback recovery" state the platform tracks
-- explicitly instead of ever letting available_balance go negative.
ALTER TABLE wallets
    ADD COLUMN receivable_balance DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER pending_balance;

-- One immutable, insert-only ledger row per financial movement a chargeback
-- causes. Never UPDATEd/DELETEd by application code — a correction is
-- always a new compensating row (see reverse_chargeback_financial_impact()
-- in includes/chargeback_service.php), so the full history is always
-- reconstructible and auditable.
CREATE TABLE wallet_ledger (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    entry_type VARCHAR(40) NOT NULL,
    reference_type VARCHAR(40) NOT NULL,
    reference_id INT UNSIGNED NOT NULL,
    -- Signed: negative = debit, positive = credit/compensating reversal.
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
    -- The provider's own dispute identifier — the natural key a chargeback
    -- is upserted on. Unique per provider so the same dispute can never be
    -- represented by two rows no matter how many webhook deliveries arrive,
    -- in what order, or how many times each repeats.
    gateway_chargeback_id VARCHAR(120) NOT NULL,
    gateway_reference VARCHAR(120) NULL,
    amount DECIMAL(18,2) NOT NULL,
    fee DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(18,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    reason_code VARCHAR(60) NULL,
    reason VARCHAR(255) NULL,
    -- Normalized, internal lifecycle — see chargeback_transition_allowed()
    -- in includes/chargeback_service.php for the guarded state machine.
    -- Never derived directly from provider_status; that's kept alongside it
    -- for reference/debugging only, never as the primary business status.
    status ENUM('open', 'pending', 'won', 'lost', 'reversed', 'cancelled') NOT NULL DEFAULT 'open',
    provider_status VARCHAR(60) NULL,
    -- Set exactly once, the moment a debit/receivable impact is actually
    -- applied (status -> lost) — guards against a duplicate/replayed event
    -- re-applying the same financial impact a second time.
    financial_impact_applied_at DATETIME NULL,
    -- The provider event timestamp that produced the CURRENT status — an
    -- out-of-order later-arriving event with an OLDER occurred_at than this
    -- is recorded (chargeback_events) but never allowed to move status.
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

-- Full inbound event timeline — every webhook delivery this platform ever
-- saw for a chargeback, applied or not. gateway_event_id is the hard
-- idempotency guard at the EVENT level (distinct from gateway_chargeback_id,
-- which identifies the DISPUTE — many events can and do target one
-- dispute). Deliberately never deleted, and never the row a merchant-facing
-- read touches directly — see chargebacks.status for that.
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
