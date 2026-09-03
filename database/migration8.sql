-- Live payout support (includes/gateway_providers/razorpay.php razorpay_create_payout(),
-- cashfree.php cashfree_create_payout()) and outbound customer webhook
-- delivery (includes/customer_webhooks.php). Apply to any database that
-- predates this.

ALTER TABLE payment_gateways
    ADD COLUMN payout_account_number VARCHAR(40) NULL AFTER public_key;

ALTER TABLE customer_api_credentials
    ADD COLUMN webhook_signing_secret_encrypted TEXT NULL AFTER payin_callback_url;
