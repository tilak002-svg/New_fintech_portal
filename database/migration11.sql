-- API request log — genuinely new observability surface, distinct from
-- audit_logs (which tracks admin/customer ACTIONS, not raw API calls).
-- Written only for bearer-token-authenticated requests, see
-- includes/auth.php::authenticate_via_bearer_token() and
-- includes/functions.php::json_response().

CREATE TABLE IF NOT EXISTS api_logs (
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
