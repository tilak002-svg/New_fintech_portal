-- Generic rate-limit counter, shared by every endpoint that needs one
-- (password reset, API token exchange, PayIn/PayOut creation, ...) rather
-- than a dedicated table per endpoint — same lookup shape as the existing
-- login_attempts table, just keyed by an arbitrary caller-chosen string
-- (e.g. "forgot_password:203.0.113.5" or "payin_create:merchant:42").
CREATE TABLE IF NOT EXISTS rate_limit_hits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rate_limit_lookup (rate_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
