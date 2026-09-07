<?php
/**
 * Money helpers. All arithmetic uses bcmath on string-typed decimal
 * amounts — never floats — to avoid rounding drift on monetary values.
 * Amounts arriving from the client are treated as intent only; every
 * figure used to mutate a balance is recalculated here server-side.
 */

const MONEY_SCALE = 2;

function money_add(string $a, string $b): string
{
    return bcadd($a, $b, MONEY_SCALE);
}

function money_sub(string $a, string $b): string
{
    return bcsub($a, $b, MONEY_SCALE);
}

function money_mul(string $a, string $b): string
{
    return bcmul($a, $b, MONEY_SCALE);
}

function money_cmp(string $a, string $b): int
{
    return bccomp($a, $b, MONEY_SCALE);
}

function money_is_positive(string $a): bool
{
    return money_cmp($a, '0.00') > 0;
}

// Chargeback fee fallback only — a real dispute webhook payload is always
// preferred when the provider reports its own fee (see
// includes/chargeback_service.php); this exists purely so a chargeback
// never goes unfeed just because a provider's payload omitted it.
const CHARGEBACK_DEFAULT_FEE = '500.00';

/**
 * Deposit fee: flat 2.5% for card, free for bank transfer.
 * Withdrawal fee: flat 1% for bank transfer, minimum 1.00.
 * Chargeback fee: flat CHARGEBACK_DEFAULT_FEE — real disputes are fixed-fee,
 * not a percentage of the disputed amount, so $amount is unused for this type.
 */
function calculate_fee(string $type, string $method, string $amount): string
{
    if ($type === 'deposit') {
        return $method === 'Debit card' ? money_mul($amount, '0.025') : '0.00';
    }

    if ($type === 'chargeback') {
        return CHARGEBACK_DEFAULT_FEE;
    }

    $fee = money_mul($amount, '0.01');
    return money_cmp($fee, '1.00') < 0 ? '1.00' : $fee;
}

function sanitize_amount(mixed $raw): ?string
{
    if (!is_string($raw) && !is_numeric($raw)) {
        return null;
    }
    $raw = trim((string) $raw);
    if (!preg_match('/^\d{1,15}(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    return bcadd($raw, '0', MONEY_SCALE);
}
