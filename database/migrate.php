<?php
/**
 * Idempotent migration runner for local/Docker use.
 *
 * Applies whichever of database/migration*.sql this database is still
 * missing, detected by inspecting information_schema rather than
 * tracking a "migrations applied" table. Safe to run any number of times
 * against any state:
 *   - a completely fresh database (schema.sql already has everything, so
 *     every check below is already satisfied and nothing runs)
 *   - a stale database from an older checkout (only the missing pieces run)
 *   - a database that's already fully up to date (no-op)
 *
 * This is what makes `docker compose up` on an old volume "just work"
 * again instead of failing with "Table ... doesn't exist" the way it did
 * before this existed — MySQL's docker-entrypoint-initdb.d only ever runs
 * schema.sql on a brand-new volume, never on one that already has data.
 *
 * Usage: php database/migrate.php
 */

require_once __DIR__ . '/../config/database.php';

$pdo = db();

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Splits a .sql file on statement-terminating semicolons and runs each
 * non-empty one. Comment lines are stripped BEFORE splitting — a naive
 * split-then-skip-comment-lines approach breaks the moment a `--` comment
 * itself contains a semicolon (this bit us once already: a migration's
 * own comment text had one mid-sentence).
 */
function run_sql_file(PDO $pdo, string $path): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $withoutComments = array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '--'));
    $sql = implode("\n", $withoutComments);

    foreach (explode(';', $sql) as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

if (!table_exists($pdo, 'users')) {
    fwrite(STDERR, "users table not found — run database/schema.sql first; this script only patches an existing install.\n");
    exit(1);
}

$applied = [];

// Predates migration.sql itself — schema.sql has had this column inline
// for a long time, but a genuinely ancient database might still be
// missing it. migration.sql no longer repeats this ALTER (it used to,
// which would make it fail as a duplicate-column error on any DB that
// already has schema.sql's inline version — i.e. almost everyone).
if (!column_exists($pdo, 'users', 'gender')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN gender ENUM('male', 'female', 'other') NULL AFTER avatar_initials");
    $applied[] = 'users.gender column';
}

if (!table_exists($pdo, 'merchant_profiles')) {
    run_sql_file($pdo, __DIR__ . '/migration.sql');
    $applied[] = 'migration.sql (merchant_profiles, settlement_banks, kyc_documents)';
}

if (!table_exists($pdo, 'platform_api_settings')) {
    run_sql_file($pdo, __DIR__ . '/migration2.sql');
    $applied[] = 'migration2.sql (platform_api_settings, platform_whitelisted_ips)';
}

if (!column_exists($pdo, 'payment_gateways', 'priority')) {
    run_sql_file($pdo, __DIR__ . '/migration3.sql');
    $applied[] = 'migration3.sql (gateway limits/usage, webhook_events, transactions.gateway_id)';
}

if (!column_exists($pdo, 'payment_gateways', 'public_key')) {
    run_sql_file($pdo, __DIR__ . '/migration4.sql');
    $applied[] = 'migration4.sql (payment_gateways.public_key)';
}

if (!column_exists($pdo, 'payment_gateways', 'sandbox_mode')) {
    run_sql_file($pdo, __DIR__ . '/migration5.sql');
    $applied[] = 'migration5.sql (payment_gateways.sandbox_mode)';
}

if (!table_exists($pdo, 'customer_api_credentials')) {
    run_sql_file($pdo, __DIR__ . '/migration6.sql');
    $applied[] = 'migration6.sql (customer_api_credentials, customer_whitelisted_ips)';
}

if (!column_exists($pdo, 'payment_gateways', 'consecutive_failures')) {
    run_sql_file($pdo, __DIR__ . '/migration7.sql');
    $applied[] = 'migration7.sql (payment_gateways circuit breaker: consecutive_failures, auto_paused_until)';
}

if (!column_exists($pdo, 'payment_gateways', 'payout_account_number')) {
    run_sql_file($pdo, __DIR__ . '/migration8.sql');
    $applied[] = 'migration8.sql (payment_gateways.payout_account_number, customer_api_credentials.webhook_signing_secret_encrypted)';
}

if (empty($applied)) {
    echo "Database already up to date — nothing to migrate.\n";
} else {
    echo "Applied:\n";
    foreach ($applied as $item) {
        echo "  - {$item}\n";
    }
}
