<?php
/**
 * Small shared helpers: output escaping, formatting, pagination.
 */

/** HTML-escape for safe output. Use on every dynamic value printed into markup. */
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Realistic per-user profile photo, sourced live from randomuser.me's
 * stable portrait endpoints (/portraits/{men|women}/0-99.jpg). Every user
 * gets one: gender-matched when it's on file, otherwise deterministically
 * assigned from the user id so it's still stable across visits. The index
 * is likewise derived from the id so the same user always gets the same photo.
 */
function user_avatar_photo_url(array $user): string
{
    $gender = $user['gender'] ?? null;
    if ($gender !== 'male' && $gender !== 'female') {
        $gender = ((int) $user['id']) % 2 === 0 ? 'female' : 'male';
    }
    $folder = $gender === 'male' ? 'men' : 'women';
    $index = ((int) $user['id']) % 100;
    return "https://randomuser.me/api/portraits/{$folder}/{$index}.jpg";
}

/** Always sized via the caller's wrapping element (w-full h-full), so callers keep controlling avatar size there. */
function user_avatar_markup(array $user): string
{
    $photoUrl = user_avatar_photo_url($user);
    $initials = e($user['avatar_initials'] ?? substr($user['name'], 0, 2));

    // onerror swaps to the initials fallback in place if the external photo fails to load.
    return '<span class="relative block w-full h-full">'
        . '<img src="' . e($photoUrl) . '" alt="" class="absolute inset-0 w-full h-full rounded-full object-cover" loading="lazy" referrerpolicy="no-referrer" '
        . 'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
        . '<span class="hidden absolute inset-0 rounded-full items-center justify-center bg-brand-muted text-brand-emphasis font-semibold">' . $initials . '</span>'
        . '</span>';
}

function money_format(string $amount, string $currency = 'INR'): string
{
    $symbols = ['INR' => '₹', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'];
    $symbol = $symbols[$currency] ?? $currency . ' ';
    return $symbol . number_format((float) $amount, 2);
}

function time_ago(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'success', 'active', 'open', 'approved' => 'badge-success',
        'pending', 'processing' => 'badge-warning',
        'failed', 'suspended', 'rejected' => 'badge-danger',
        'refunded', 'info' => 'badge-info',
        default => 'badge-neutral',
    };
}

function status_label(string $status): string
{
    return ucfirst(str_replace('_', ' ', $status));
}

/**
 * Reads and validates a page/per_page pair from query params for
 * server-side pagination. Always returns safe, bounded integers.
 */
function paginate_params(int $defaultPerPage = 20, int $maxPerPage = 100): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? $defaultPerPage);
    $perPage = max(1, min($maxPerPage, $perPage));
    return [$page, $perPage, ($page - 1) * $perPage];
}

/**
 * One id per request, generated on first use and memoized — cheap
 * end-to-end tracing without a request-scoped DI container. Returned in
 * every JSON response body and the X-Request-ID header (see json_response())
 * so a customer reporting "my payin failed" can hand back one id that's
 * traceable through api_logs, audit_logs, and error_log() output.
 */
function request_id(): string
{
    static $id = null;
    if ($id === null) {
        $id = 'req_' . bin2hex(random_bytes(8));
    }
    return $id;
}

/**
 * $errorCode is optional and purely additive — a short machine-readable
 * string (e.g. 'INVALID_CREDENTIALS', 'IP_NOT_WHITELISTED', 'RATE_LIMITED')
 * for callers that want to branch on something more stable than the
 * human-readable $message. Omitting it (the default) is fully backward
 * compatible with every existing call site.
 */
function json_response(bool $success, $data = null, string $message = '', int $statusCode = 200, ?string $errorCode = null): never
{
    // Logs genuine merchant-API traffic only — the flag is set exclusively
    // by authenticate_via_bearer_token() (includes/auth.php), never by the
    // ordinary session+CSRF path, so a normal dashboard page load never
    // creates a row here. See database's api_logs table.
    if (isset($GLOBALS['__api_log_user_id']) && function_exists('db')) {
        try {
            $endpoint = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            db()->prepare(
                'INSERT INTO api_logs (user_id, method, endpoint, http_status, ip_address) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $GLOBALS['__api_log_user_id'],
                $_SERVER['REQUEST_METHOD'] ?? '',
                $endpoint,
                $statusCode,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Throwable $e) {
            error_log('[api_logs] failed to write log entry: ' . $e->getMessage());
        }
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    header('X-Request-ID: ' . request_id());
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'error_code' => $errorCode,
        'request_id' => request_id(),
    ]);
    exit;
}

/**
 * Generic sliding-window rate limiter backed by rate_limit_hits. $key
 * should already be scoped by the caller (e.g. "forgot_password:<ip>" or
 * "payin_create:merchant:<id>") — this function only counts and records,
 * it doesn't know what the key means.
 *
 * Occasionally (1-in-50 calls) prunes hits older than a day so this table
 * doesn't grow unbounded — cheap enough to run inline given how rarely it
 * fires, and avoids needing a cron just for housekeeping.
 */
function rate_limit_check(string $key, int $maxHits, int $windowSeconds): bool
{
    $pdo = db();

    if (random_int(1, 50) === 1) {
        try {
            $pdo->exec("DELETE FROM rate_limit_hits WHERE created_at < (NOW() - INTERVAL 1 DAY)");
        } catch (Throwable $e) {
            error_log('[rate_limit] prune failed: ' . $e->getMessage());
        }
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM rate_limit_hits WHERE rate_key = ? AND created_at > (NOW() - INTERVAL ? SECOND)'
    );
    $stmt->execute([$key, $windowSeconds]);
    if ((int) $stmt->fetchColumn() >= $maxHits) {
        return false;
    }

    $pdo->prepare('INSERT INTO rate_limit_hits (rate_key) VALUES (?)')->execute([$key]);
    return true;
}

/**
 * Maps a PayIn/PayOut failure's HTTP status to a stable machine-readable
 * code — create_payin()/create_payout() (includes/payin_service.php,
 * payout_service.php) return a status_code but no code of their own, and
 * duplicating a code onto every one of their return statements would be a
 * lot of churn for what's really just a handful of distinct failure
 * shapes. This maps at the endpoint boundary instead.
 */
function payin_payout_error_code(int $statusCode): string
{
    return match ($statusCode) {
        422 => 'VALIDATION_ERROR',
        429 => 'RATE_LIMITED',
        502 => 'GATEWAY_ERROR',
        503 => 'GATEWAY_UNAVAILABLE',
        default => 'REQUEST_FAILED',
    };
}

/** Convenience wrapper — ends the request with 429 if the limit is exceeded. */
function enforce_rate_limit(string $key, int $maxHits, int $windowSeconds, string $message = 'Too many requests. Please try again shortly.'): void
{
    if (!rate_limit_check($key, $maxHits, $windowSeconds)) {
        json_response(false, null, $message, 429, 'RATE_LIMITED');
    }
}

/**
 * Canonical list of KYC document slots — shared by the KYC Verification
 * page and its API endpoints so the UI and the DB enum never drift apart.
 */
function kyc_document_types(): array
{
    return [
        'aadhar_card' => 'Aadhar Card',
        'pan_card' => 'PAN Card',
        'gst_certificate' => 'GST Certificate',
        'board_resolution' => 'Board Resolution',
        'certificate_of_incorporation' => 'Certificate of Incorporation',
        'passport_photo' => 'Passport Size Photo',
        'service_agreement' => 'Service Agreement',
    ];
}

/**
 * Minimal HS256 JWT encoder/decoder for platform API bearer tokens
 * (Payment gateways → API Credentials → Generate Bearer Token). No external
 * library needed — HS256 is just base64url(header).base64url(payload)
 * signed with HMAC-SHA256, which PHP's hash_hmac() does natively.
 */
function jwt_encode(array $payload, string $secret): string
{
    $b64url = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

    $header = $b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body = $b64url(json_encode($payload));
    $signature = $b64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

    return "{$header}.{$body}.{$signature}";
}

/**
 * Verifies signature + expiry and returns the decoded payload, or null if
 * the token is malformed, mis-signed, or expired. Available for any
 * endpoint that wants to accept the platform bearer token as an
 * authentication method going forward.
 */
function jwt_decode_verify(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$header, $body, $signature] = $parts;

    $b64url = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $expected = $b64url(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['exp']) && time() >= (int) $payload['exp']) {
        return null;
    }

    return $payload;
}

function generate_reference(string $type): string
{
    $prefix = $type === 'deposit' ? 'DX' : 'WX';
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Translates the internal transactions.type enum ('deposit'/'withdrawal')
 * to the merchant-facing PayIn/PayOut vocabulary, without widening that
 * live-data column — see includes/payin_service.php for the rationale.
 */
function transaction_type_public_name(string $type): string
{
    return $type === 'deposit' ? 'payin' : 'payout';
}

/**
 * The API Base URL shown to every customer (Settings/API Access, API
 * documentation) — the single source every page must call instead of
 * inlining `rtrim(APP_URL, '/') . '/api/v1'`, so an admin's override
 * (Admin Dashboard → API Base URL → Edit) takes effect everywhere at
 * once. Falls back to the APP_URL-derived default when no override has
 * ever been saved, so this is safe to call before an admin has touched
 * the setting.
 */
function platform_api_base_url(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = db()->query('SELECT api_base_url FROM platform_settings WHERE id = 1');
    $override = $stmt ? $stmt->fetchColumn() : null;

    $cached = $override ?: (rtrim(APP_URL, '/') . '/api/v1');
    return $cached;
}

function current_route(): string
{
    return $_GET['route'] ?? 'dashboard';
}
