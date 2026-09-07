<?php
/**
 * Demo data seeder. Run once after importing schema.sql:
 *   php database/seed.php
 *
 * Creates demo accounts with properly hashed passwords (password_hash()
 * needs a live PHP runtime, so this is a script rather than static SQL).
 * Safe to re-run: it wipes and rebuilds the demo rows only.
 */

require_once __DIR__ . '/../config/database.php';

$pdo = db();

// TRUNCATE is DDL and implicitly commits any open transaction in MySQL,
// so this cleanup runs outside the transaction used for the inserts below.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'audit_logs', 'api_logs', 'notifications', 'support_messages', 'support_conversations',
    'gateway_daily_usage', 'gateway_hourly_usage', 'gateway_monthly_usage', 'webhook_events',
    'payment_sessions', 'transactions', 'wallets', 'business_profiles', 'merchant_profiles',
    'settlement_banks', 'kyc_documents', 'customer_whitelisted_ips', 'customer_api_credentials',
    'platform_whitelisted_ips', 'platform_api_settings', 'login_attempts', 'payment_gateways', 'users',
] as $table) {
    $pdo->exec("TRUNCATE TABLE {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$pdo->beginTransaction();

try {
    $demoPassword = 'Demo!2024pass';
    $hash = password_hash($demoPassword, PASSWORD_DEFAULT);

    $insertUser = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, status, avatar_initials, gender) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $users = [
        ['Ava Whitfield', 'admin@verapay.test', 'admin', 'active', 'AW', 'female'],
        ['Marcus Deng', 'operator@verapay.test', 'operator', 'active', 'MD', 'male'],
        ['Priya Natarajan', 'priya@verapay.test', 'customer', 'active', 'PN', 'female'],
        ['Jonah Ferreira', 'jonah@verapay.test', 'customer', 'active', 'JF', 'male'],
    ];

    $userIds = [];
    foreach ($users as [$name, $email, $role, $status, $initials, $gender]) {
        $insertUser->execute([$name, $email, $hash, $role, $status, $initials, $gender ]);
        $userIds[$email] = (int) $pdo->lastInsertId();
    }

    // No wallet rows seeded here — a merchant's wallet (settlement ledger)
    // is created lazily on their first real PayIn/PayOut, same as
    // production (see includes/payin_service.php's INSERT IGNORE). No
    // demo deposit/withdrawal transactions either — that browser wallet
    // flow was removed; PayIn/PayOut activity is created via the API
    // (see the "Try it" tester on /api-docs) or public/api/v1/*, not seeded.

    $priya = $userIds['priya@verapay.test'];
    $now = new DateTime();

    $insertConversation = $pdo->prepare(
        'INSERT INTO support_conversations (user_id, subject, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
    );
    $insertConversation->execute([$priya, 'PayIn webhook not received for a completed payment', 'open', (clone $now)->modify('-2 days')->format('Y-m-d H:i:s'), (clone $now)->modify('-1 hours')->format('Y-m-d H:i:s')]);
    $conversationId = (int) $pdo->lastInsertId();

    $insertMessage = $pdo->prepare(
        'INSERT INTO support_messages (conversation_id, sender_id, sender_role, message, created_at, read_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertMessage->execute([$conversationId, $priya, 'customer', 'One of our customers paid successfully but our payin_callback_url never received a webhook for it. Can you check on it?', (clone $now)->modify('-2 days')->format('Y-m-d H:i:s'), (clone $now)->modify('-2 days +1 hour')->format('Y-m-d H:i:s')]);
    $insertMessage->execute([$conversationId, $userIds['operator@verapay.test'], 'operator', "Thanks for flagging this, Priya. I'm checking the webhook delivery log now and will update you shortly.", (clone $now)->modify('-1 days')->format('Y-m-d H:i:s'), (clone $now)->modify('-1 days +2 hours')->format('Y-m-d H:i:s')]);

    $insertGateway = $pdo->prepare(
        'INSERT INTO payment_gateways (display_name, provider, api_key_last4, api_key_hash, status, is_default, priority, daily_limit_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    // Priority + daily_limit_amount mirror the worked example from the
    // gateway rotation spec, so a fresh seed can exercise selection,
    // limit-skipping and rotation immediately without any manual admin setup.
    $demoGateways = [
        ['Primary Processor', 'razorpay', 'a1B9', 'active', 1, 1, '10000.00', '-40 days'],
        ['Backup Processor', 'payu', 'x02F', 'active', 0, 2, '20000.00', '-12 days'],
        ['Card Network Direct', 'stripe', 'k77Q', 'inactive', 0, 3, '50000.00', '-3 days'],
    ];
    foreach ($demoGateways as [$name, $provider, $last4, $status, $isDefault, $priority, $dailyLimit, $daysOffset]) {
        $insertGateway->execute([$name, $provider, $last4, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $status, $isDefault, $priority, $dailyLimit, (clone $now)->modify($daysOffset)->format('Y-m-d H:i:s')]);
    }

    // Obviously-fictional placeholder KYC data (repeated/sequential digits,
    // not a real assigned PAN/GSTIN/Aadhaar/account number for anyone).
    $pdo->prepare(
        'INSERT INTO business_profiles
            (user_id, legal_company_name, company_type, mobile_number, whatsapp_number, pan_number, gstin,
             office_address, identity_last4, identity_hash, bank_account_holder, bank_account_last4, bank_account_hash, bank_ifsc)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $priya, 'Verapay Traders Pvt Ltd', 'Private Limited', '90000 00000', '90000 00000', 'AAAAA0000A', '29AAAAA0000A1Z5',
        '123 Example Street, Sample Layout, Bengaluru, Karnataka - 560001',
        '0000', password_hash('000000000000', PASSWORD_DEFAULT),
        'Priya Natarajan', '4821', password_hash('000000004821', PASSWORD_DEFAULT), 'HDFC0001234',
    ]);

    $insertNotification = $pdo->prepare(
        'INSERT INTO notifications (user_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insertNotification->execute([$priya, 'support', 'Support reply received', 'An operator replied to your conversation about a missing PayIn webhook.', 0, (clone $now)->modify('-1 days')->format('Y-m-d H:i:s')]);

    $pdo->commit();

    echo "Seed complete.\n\n";
    echo "Demo accounts (password for all: {$demoPassword}):\n";
    echo "  Admin:    admin@verapay.test\n";
    echo "  Operator: operator@verapay.test\n";
    echo "  Customer: priya@verapay.test\n";
    echo "  Customer: jonah@verapay.test\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
