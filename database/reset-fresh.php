<?php
/**
 * Wipes ALL test/demo/transactional data and leaves exactly one clean
 * admin account — for manually testing the full onboarding -> PayIn flow
 * from a genuinely empty platform state. Destructive; local/dev use only,
 * never run this against a database with real data.
 *
 * Usage: php database/reset-fresh.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$pdo = db();

$adminEmail = 'admin@verapay.test';
$adminPassword = 'Demo!2024pass';

// TRUNCATE is DDL and implicitly commits any open transaction in MySQL —
// same reasoning as database/seed.php. FK checks off so table order
// doesn't matter.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'audit_logs', 'api_logs', 'rate_limit_hits', 'login_attempts', 'password_resets',
    'notifications', 'support_messages', 'support_conversations',
    'customer_webhook_deliveries', 'webhook_events', 'payment_sessions',
    'gateway_daily_usage', 'gateway_hourly_usage', 'gateway_monthly_usage',
    'transactions', 'merchant_gateway_assignments', 'payment_gateways',
    'wallets', 'business_profiles', 'merchant_profiles', 'settlement_banks', 'kyc_documents',
    'customer_whitelisted_ips', 'customer_api_credentials',
    'platform_whitelisted_ips', 'platform_api_settings',
    'users',
] as $table) {
    $pdo->exec("TRUNCATE TABLE {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$hash = password_hash($adminPassword, PASSWORD_DEFAULT);
$pdo->prepare(
    'INSERT INTO users (name, email, password_hash, role, status, must_change_password, avatar_initials, gender)
     VALUES (?, ?, ?, "admin", "active", 0, ?, NULL)'
)->execute(['Admin', $adminEmail, $hash, 'AD']);

// Uploaded KYC files live on disk (KYC_UPLOAD_DIR), not just the DB row
// truncated above — clear those too so a re-submitted document doesn't
// collide with a stale file left over from a previous test user.
if (is_dir(KYC_UPLOAD_DIR)) {
    $dirs = glob(KYC_UPLOAD_DIR . '/*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
    }
}

echo "Reset complete. Platform is empty except for one admin account:\n";
echo "  Email:    {$adminEmail}\n";
echo "  Password: {$adminPassword}\n";
