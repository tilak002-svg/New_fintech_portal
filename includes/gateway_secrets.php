<?php
/**
 * Reversible storage for real payment gateway API secrets.
 *
 * payment_gateways.api_key_hash (password_hash/bcrypt) is one-way and can
 * never be turned back into the plaintext key an outbound API call needs
 * — it only ever proved useful for "does this match" checks, which nothing
 * in this codebase actually does. api_key_encrypted holds the same secret
 * encrypted with AES-256-GCM under GATEWAY_ENCRYPTION_KEY (env-only, never
 * stored in the database), so it can be decrypted back for real outbound
 * calls while still never appearing in the DB as plaintext.
 */

/**
 * A key/secret decrypt or configuration failure — deliberately distinct
 * from a provider's own rejection (a plain RuntimeException thrown inside
 * razorpay.php/cashfree.php). Both propagate up through
 * create_gateway_order()/create_gateway_payout() uncaught and land in the
 * same generic catch block in payin_service.php/payout_service.php, but
 * they mean opposite things for a gateway-health alert: this one is always
 * "our application" (a wrong/rotated GATEWAY_ENCRYPTION_KEY, or corrupted
 * stored ciphertext), never the provider's fault — see the dedicated catch
 * for this type in create_payin()/create_payout().
 */
class GatewayConfigurationException extends RuntimeException {}

function gateway_encrypt_secret(string $plaintext): string
{
    $key = gateway_encryption_key();
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false) {
        throw new GatewayConfigurationException('Failed to encrypt gateway secret.');
    }
    return base64_encode($iv . $tag . $ciphertext);
}

function gateway_decrypt_secret(string $encoded): string
{
    $key = gateway_encryption_key();
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) {
        throw new GatewayConfigurationException('Malformed encrypted gateway secret.');
    }

    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);

    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plaintext === false) {
        throw new GatewayConfigurationException('Failed to decrypt gateway secret — key mismatch or corrupted data.');
    }
    return $plaintext;
}

function gateway_encryption_key(): string
{
    $configured = GATEWAY_ENCRYPTION_KEY;
    if (!$configured) {
        throw new GatewayConfigurationException('GATEWAY_ENCRYPTION_KEY is not configured. Set it in .env before creating or rotating gateway secrets.');
    }

    $key = base64_decode((string) $configured, true);
    if ($key === false || strlen($key) !== 32) {
        throw new GatewayConfigurationException('GATEWAY_ENCRYPTION_KEY must decode to exactly 32 bytes (base64 of a 256-bit key).');
    }
    return $key;
}
