-- Hourly/monthly/per-transaction gateway limit granularity, extending the
-- existing daily-only mechanism in includes/gateway_selector.php. Mirrors
-- migration3.sql's daily_limit_amount/gateway_daily_usage shape exactly.

ALTER TABLE payment_gateways
    ADD COLUMN hourly_limit_amount DECIMAL(18,2) NULL AFTER daily_limit_amount,
    ADD COLUMN monthly_limit_amount DECIMAL(18,2) NULL AFTER hourly_limit_amount,
    ADD COLUMN per_transaction_limit_amount DECIMAL(18,2) NULL AFTER monthly_limit_amount;

-- One row per gateway per hour (usage_hour = the hour bucket's start,
-- e.g. '2026-09-04 11:00:00'), locked with SELECT ... FOR UPDATE at
-- selection time exactly like gateway_daily_usage.
CREATE TABLE IF NOT EXISTS gateway_hourly_usage (
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
CREATE TABLE IF NOT EXISTS gateway_monthly_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT UNSIGNED NOT NULL,
    usage_month CHAR(7) NOT NULL,
    used_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gateway_monthly_usage (gateway_id, usage_month),
    CONSTRAINT fk_gateway_monthly_usage_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
