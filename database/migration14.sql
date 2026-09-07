-- Forces a customer created by admin (with an admin-chosen temporary
-- password) to set their own password before reaching anything else.
-- Cleared by the same public/api/settings/change-password.php endpoint
-- used for ordinary password changes — enforcement lives in
-- public/index.php's routing, not in the endpoint itself.
ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
