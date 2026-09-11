-- Customer self-service IP whitelist requests (replaces admin direct-add).
-- Every row now carries a review status instead of being implicitly active
-- the moment it exists — a customer can propose an IP, but only an admin
-- decision (approve-api-ip.php / reject-api-ip.php) makes it usable by the
-- bearer-token IP gate (includes/auth.php, public/api/auth/api-token.php).
-- A missing row and a 'pending'/'rejected' row must be treated identically
-- by that gate (fail closed either way).
--
-- Deliberately NOT backfilled to 'approved' for pre-existing rows: every
-- row, including ones an admin already added under the old direct-add
-- flow, becomes 'pending' via the column DEFAULT. This intentionally means
-- currently-working merchant integrations will 403 (IP_NOT_WHITELISTED)
-- until an admin re-approves each pre-existing row after this migration
-- runs — see the rollout note that shipped alongside this change.
--
-- added_by keeps its legacy meaning ("admin who added this under the old
-- direct-add flow", NULL for self-requested rows); reviewed_by is who
-- approved/rejected it.
ALTER TABLE customer_whitelisted_ips
    ADD COLUMN status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' AFTER ip_address,
    ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER added_by,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
    ADD CONSTRAINT fk_customer_whitelisted_ips_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    ADD KEY idx_customer_whitelisted_ips_user_status (user_id, status);
