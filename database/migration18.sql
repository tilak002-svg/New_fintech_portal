-- Closes a real gateway-selection bug: select_and_reserve_gateway() picked
-- ANY active gateway purely on priority/limits, with no check that the
-- gateway is actually usable — a Razorpay row saved with no credentials
-- (possible via direct DB/seed data, though the admin "Add gateway" API
-- itself already requires them) would win the priority race over a fully
-- configured Cashfree gateway, and the caller would then silently fall
-- back to the local instant-success sandbox path. That fallback is only
-- ever correct for a gateway the admin explicitly marked as a mock/test
-- gateway (is_mock) — never as an accidental consequence of missing
-- credentials on a gateway claiming to be a real provider.
--
-- payin_enabled/payout_enabled close a second gap: every gateway was
-- eligible for BOTH directions regardless of what it's actually configured
-- for (e.g. gateway_supports_live_payout() already distinguishes this per
-- provider, but selection itself never consulted it).
--
-- min_ticket_size/max_ticket_size are a distinct restriction from
-- per_transaction_limit_amount (a hard ceiling only) — see
-- includes/gateway_selector.php.
ALTER TABLE payment_gateways
    ADD COLUMN payin_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default,
    ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER payin_enabled,
    ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 AFTER payout_enabled,
    ADD COLUMN min_ticket_size DECIMAL(18,2) NULL AFTER per_transaction_limit_amount,
    ADD COLUMN max_ticket_size DECIMAL(18,2) NULL AFTER min_ticket_size;
