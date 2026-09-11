-- Merchant-wise gateway assignment + per-merchant priority, distinct from
-- payment_gateways.priority (which remains the gateway's own default/
-- fallback ordering, pre-filled when admin assigns it to a new merchant,
-- and still drives the "Manage Gateways" list's own display order).
-- Deliberately not payin/payout-specific (no `direction` column) so a
-- future payout routing pass can read the same table without a new one.
--
-- Fails closed: a merchant with zero (or zero enabled) rows here gets
-- 'no_assigned_gateways' from select_and_reserve_gateway() and cannot
-- create a PayIn/PayOut — see includes/gateway_selector.php.
CREATE TABLE merchant_gateway_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    gateway_id INT UNSIGNED NOT NULL,
    priority INT UNSIGNED NOT NULL DEFAULT 100,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_merchant_gateway (user_id, gateway_id),
    KEY idx_merchant_gateway_priority (user_id, is_enabled, priority),
    CONSTRAINT fk_mga_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mga_gateway FOREIGN KEY (gateway_id) REFERENCES payment_gateways(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Grandfather every existing customer onto every currently-active gateway
-- (payin OR payout enabled - this table isn't direction-specific, so a
-- payout-only gateway must be grandfathered too, or existing payout flows
-- would silently break the moment payout routing starts reading this same
-- table, even though payout itself isn't being touched by this change) at
-- that gateway's current global priority, so no existing merchant
-- integration breaks the moment this ships.
INSERT INTO merchant_gateway_assignments (user_id, gateway_id, priority, is_enabled)
SELECT u.id, pg.id, pg.priority, 1
FROM users u
CROSS JOIN payment_gateways pg
WHERE u.role = 'customer' AND pg.status = 'active' AND (pg.payin_enabled = 1 OR pg.payout_enabled = 1);
