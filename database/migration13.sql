-- Tracks when a customer last successfully ran the Key/API Verification
-- check (pages/key-verification.php) — purely informational UI state, not
-- a security gate, so it's safe to set from a client-confirmed success.
ALTER TABLE customer_api_credentials
    ADD COLUMN last_verified_at DATETIME NULL AFTER webhook_signing_secret_encrypted;
