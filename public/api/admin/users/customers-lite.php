<?php
/**
 * Lightweight {id, name, email} list of every customer, for populating a
 * "filter by customer" dropdown on the admin PayIns/PayOuts/Transactions
 * pages — deliberately not the same query as users/list.php (which joins
 * KYC counts and paginates for the Customers page itself); this just needs
 * every customer's identity, nothing else, to build <option> elements.
 */
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/functions.php';

$user = require_auth();
require_role($user, 'admin', 'operator');

$stmt = db()->query(
    "SELECT id, name, email FROM users WHERE role = 'customer' ORDER BY name ASC"
);

json_response(true, ['customers' => $stmt->fetchAll()], 'ok');
