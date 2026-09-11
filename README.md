# Verapay

A payment operations dashboard: customer wallet/deposits/withdrawals/transactions/support, plus an admin/operator console with user suspension, a support inbox, and an audit log.

Vanilla PHP (PDO, no framework), MySQL/MariaDB, Tailwind CSS (compiled, no runtime Node dependency), and plain fetch-based JavaScript.

## 1. Project structure

```
/public                 Document root — point your web server here
  index.php             Front controller / router (reads the route from the URL path)
  .htaccess             Apache rewrite + security headers
  /api                  JSON endpoints, grouped by feature
  /assets/css           tokens.css (design tokens) + app.css (Tailwind source) + app.build.css (compiled, generated)
  /assets/js            app.js (shared: toasts, modals, dropdowns) + charts.js + one file per page
  /assets/icons         icons.php — inline SVG icon() helper

/config                 config.php, database.php, env.php — outside the document root, include-only
/includes               auth.php (session/CSRF/suspension middleware), money.php (bcmath helpers),
                         functions.php, and shared layout partials (header/sidebar/navbar/footer/modal)
/pages                  View templates included by public/index.php (not directly web-accessible)
/database               schema.sql + seed.php (demo data)

.env.example            Copy to .env and fill in real values
tailwind.config.js, package.json   Build-time only — Tailwind CLI, no Node needed to run the app
docker-compose.yml, docker/        Local dev convenience (MySQL + PHP containers) — not required for real hosting
```

**Why config/includes/pages sit outside `/public`:** they're PHP that should never be served as a raw file if a server is misconfigured. Point your document root at `/public`; everything else is reached only through `require`.

## 2. Requirements

- PHP 8.1+ with `pdo_mysql` and `bcmath` extensions
- MySQL 8.0+ or MariaDB 10.6+
- Node.js (build time only, to compile Tailwind CSS — not required to run the app)
- Apache with `mod_rewrite` (or Nginx — see §6), or PHP's built-in server for local dev

## 3. Getting started (step by step, for a new developer)

This is the exact path to get the app running on your own machine, from nothing installed to a working login screen. No PHP or MySQL install needed — Docker runs both for you.

**Already have Docker installed and the repo cloned?** Steps 3-7 below are automated — run `.\dev-up.ps1` (Windows PowerShell) or `./dev-up.sh` (Mac/Linux/Git Bash) from the project root and skip to §6. Safe to re-run; it won't overwrite an existing `.env` or clobber `GATEWAY_ENCRYPTION_KEY` if one's already set.

**Step 1 — Install Docker Desktop.**
Download it from [docker.com/products/docker-desktop](https://www.docker.com/products/docker-desktop/) and install it. Open it once and make sure it says it's running (there's a whale icon in your system tray/menu bar). Everything below is typed into a terminal — Command Prompt, PowerShell, or Git Bash on Windows; Terminal on Mac.

**Step 2 — Get the project code.**
Whoever gave you access to the repo will give you a URL. Then:
```bash
git clone <the-repo-url> verapay
cd verapay
```
If you were just handed a folder/zip instead of a git URL, skip `git clone` and just `cd` into that folder.

**Step 3 — Create your local config file.**
The project ships a *template* config (`.env.example`) but never a real `.env` — you make your own copy:
```bash
cp .env.example .env
```
(On Windows Command Prompt, use `copy .env.example .env` instead.) You don't need to edit anything inside it — the defaults already match the Docker setup in the next step.

**Step 4 — Start the app and database.**
```bash
docker compose up -d --build
```
First run takes a minute or two — it's downloading the PHP and MySQL images and building the app container. You'll know it worked when the last few lines say things like `Container verapay-app-1 Started`.

**Step 5 — Load the database.**
The database tables are created automatically the first time MySQL starts up. You still need to add the demo data (test accounts, sample transactions) once:
```bash
docker compose exec app php database/seed.php
```
You should see a `Seed complete.` message with a list of demo accounts.

**Step 6 — Open it in your browser.**
Go to **http://localhost:8080/login**. You should see the Verapay sign-in page.

**Step 7 — Log in.**
Use any account from the table in §8 — e.g. email `priya@verapay.test`, password `Demo!2024pass`.

That's it — you're in. To stop everything later:
```bash
docker compose down       # stops the containers; your database data is kept
```
To start it again another day, you only need `docker compose up -d` (no `--build`, no reseeding).

**If something goes wrong:**
- *"port is already allocated"* when running `docker compose up` → something else on your PC is already using port 8080. Open `docker-compose.yml`, change the `"8080:8000"` line to e.g. `"8081:8000"`, then also change `APP_URL` in your `.env` to match, and try again.
- *Blank page or "can't connect"* → check Docker Desktop is actually running, and run `docker compose ps` — both `app` and `db` should say `Up`/`healthy`.
- *Login says incorrect password* → you likely skipped Step 5; run the seed command again.
- Need to see server errors? `docker compose logs -f app`

## 4. Install without Docker

For deploying to real PHP hosting, or if you'd rather run PHP/MySQL natively:

```bash
# 1. Build tooling — no Composer packages, just the Tailwind CLI (skip this if you're
#    not changing styles; app.build.css is already committed and works as-is):
npm install
npm run build:css        # compiles public/assets/css/app.build.css

# 2. Configure environment
cp .env.example .env
# edit .env: set DB_HOST to your MySQL server (see the comment in .env.example)

# 3. Create the database schema
mysql -u root -p -e "CREATE DATABASE verapay CHARACTER SET utf8mb4"
mysql -u root -p verapay < database/schema.sql

# 4. Seed demo data (creates the accounts below with properly bcrypt-hashed passwords)
php database/seed.php
```

## 5. Environment variables (`.env`)

| Variable | Purpose |
|---|---|
| `APP_NAME`, `APP_URL` | Display name and base URL |
| `APP_ENV`, `APP_DEBUG` | `APP_DEBUG=false` in production — suppresses PHP error output (errors are still logged) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `SESSION_LIFETIME_MINUTES` | Idle session timeout |
| `LOGIN_MAX_ATTEMPTS`, `LOGIN_LOCKOUT_MINUTES` | Login rate limiting |

Credentials are never hard-coded anywhere in the codebase — only read from `.env` via `config/env.php`.

## 6. Run locally (without Docker)

```bash
php -S localhost:8000 -t public public/router.php
```

The router script is required — without it, PHP's built-in server 404s directly (never reaching `public/index.php`) for any URL whose directory prefix happens to exist on disk, e.g. `/api/v1/balance` (no `.php`), because `public/api/v1/` is a real directory even though `balance` isn't a real file in it. `public/router.php` serves real files (`public/api/*.php`, assets, …) as-is and forwards everything else to `public/index.php` — the same behavior Apache (`.htaccess`) and Nginx already give you natively, so this file is dev-server-only.

## 7. Deploy (conventional PHP hosting)

1. Point the domain/subdomain's **document root at `/public`**. On most shared hosting (cPanel etc.) this is a document-root setting, not a folder you move.
2. Ensure `mod_rewrite` is enabled — `public/.htaccess` handles routing and sets basic security headers.
   - **Nginx** equivalent:
     ```nginx
     location / {
         try_files $uri $uri/ /index.php;
     }
     location ~ \.php$ {
         fastcgi_pass unix:/run/php/php8.3-fpm.sock;
         fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
         include fastcgi_params;
     }
     ```
3. Set `.env` with production DB credentials and `APP_DEBUG=false`.
4. Run `npm run build:css` before deploying (or commit `app.build.css`) — the app never fetches Tailwind at runtime.
5. Serve over HTTPS — session cookies are marked `Secure` automatically once the request arrives over HTTPS (see `includes/auth.php`).
6. If your host can't point the document root at `/public`, the root `.htaccess` blocks direct access to `config/`, `includes/`, `pages/`, and `database/` as a defense-in-depth fallback — but a correctly set document root is the supported path.
7. **Schedule the two background workers — REQUIRES EXTERNAL CONFIGURATION.** This app has no queue/daemon of its own; `bin/reconcile-pending.php` and `bin/process-webhook-retries.php` are plain CLI scripts that do nothing until something invokes them on a schedule. Nothing in code makes this happen automatically. Add to crontab (Linux) or Task Scheduler (Windows):
   ```cron
   */2 * * * *  php /path/to/app/bin/process-webhook-retries.php >> /var/log/verapay-webhooks.log 2>&1
   */5 * * * *  php /path/to/app/bin/reconcile-pending.php     >> /var/log/verapay-reconcile.log 2>&1
   ```
   Until this is configured, retries and reconciliation only happen when an admin clicks "Retry due deliveries now" / "Reconcile pending transactions" on **Admin → Webhooks** — real, working, but manual.

## 8. Demo accounts

Created by `php database/seed.php`. Password for all: **`Demo!2024pass`**

| Role | Email |
|---|---|
| Admin | `admin@verapay.test` |
| Operator | `operator@verapay.test` |
| Customer | `priya@verapay.test` |
| Customer | `jonah@verapay.test` |

These are demo-only accounts seeded into your own local/staging database — not credentials to any external service.

## 9. Database schema

See `database/schema.sql` for full DDL. Summary:

- **users** — role (`customer`/`operator`/`admin`), status (`active`/`suspended`)
- **wallets** — one per user; `available_balance` / `pending_balance`, both `DECIMAL(18,2)`
- **business_profiles** — customer KYC/settlement details; identity document and bank account numbers are hashed + last-4 only, never stored or returned in full
- **transactions** — unified deposit/withdrawal ledger (`type` column); Deposits and Withdrawals pages are filtered views of this table
- **support_conversations** / **support_messages** — persisted chat, shared between customer and operator views
- **notifications** — per-user, `is_read` flag
- **payment_gateways** — configured processors; each API key is stored both as a one-way hash (display only, last 4 characters shown) and AES-256-GCM encrypted (reversible, for real outbound calls — see `includes/gateway_secrets.php`). Razorpay/Cashfree gateways with credentials set place real orders/payouts at the provider; see `includes/gateway_providers/`
- **audit_logs** — actor, action, target, metadata, IP, timestamp
- **login_attempts** — backs login rate limiting

All monetary values are `DECIMAL`, never float. Fee/balance math runs through `includes/money.php`, which uses `bcmath` on string-typed amounts exclusively. Currency defaults to `INR` (₹).

## 10. Authentication & security model

- PHP native sessions; `session_regenerate_id()` on login (fixation prevention); idle timeout via `SESSION_LIFETIME_MINUTES`; cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS.
- CSRF token minted per session, sent as `X-CSRF-Token` on every mutating `fetch()`, verified server-side on every `POST`/`PUT`/`PATCH`/`DELETE`.
- **`require_auth()`** (in `includes/auth.php`) is called at the top of every page and every API endpoint. It re-queries the user's live `status` from the database on every single request — this is the entire mechanism behind suspension taking effect immediately: there's no session-token table to separately revoke, because the status check itself gates every request.
- All queries use PDO prepared statements with `PDO::ATTR_EMULATE_PREPARES => false` (real server-side prepares).
- Output is escaped via `e()` (an `htmlspecialchars` wrapper) everywhere user data reaches HTML.
- IDOR checks: support conversations/messages verify the requesting user owns the conversation (or is staff) before returning anything; notifications are scoped to `user_id` on every read/write.
- Login attempts are rate-limited per email+IP with a rolling lockout window.

## 11. API reference

All endpoints return `{ "success": bool, "data": ..., "message": "...", "error_code": string|null, "request_id": "req_..." }` — `error_code` is a stable machine-readable string (`INVALID_CREDENTIALS`, `IP_NOT_WHITELISTED`, `RATE_LIMITED`, `VALIDATION_ERROR`, ...) for the highest-traffic/security-relevant endpoints; other endpoints still return `error_code: null` on failure (only `message` is guaranteed everywhere). `request_id` is also echoed as an `X-Request-ID` response header, for tracing one failure across `api_logs`/`audit_logs`/server logs. Mutating endpoints require the `X-CSRF-Token` header. All require an authenticated session unless noted.

| Endpoint | Method | Notes |
|---|---|---|
| `/api/auth/login.php` | POST | Public. Rate-limited. |
| `/api/auth/logout.php` | POST | |
| `/api/auth/csrf-token.php` | GET | Public. Mints/returns the current session's CSRF token — used by the auth pages to silently recover from a stale embedded token instead of failing until a manual refresh. |
| `/api/admin/reconciliation/run.php` | POST | Admin only. Manually runs the same reconciliation pass as `bin/reconcile-pending.php` (§7). |
| `/api/admin/webhooks/retry-now.php` | POST | Admin only. Manually runs the same retry pass as `bin/process-webhook-retries.php` (§7). |
| `/api/dashboard/summary.php` | GET | Scoped to caller; admins/operators get platform-wide figures |
| `/api/wallet/summary.php` | GET | Customer only |
| `/api/deposits/create.php` | POST | Customer only (session or bearer token). Server recalculates fee/net amount — never trusts client math. Places a real order at the gateway's provider (Razorpay/Cashfree) when one is configured |
| `/api/withdrawals/create.php` | POST | Customer only (session or bearer token). Row-locks the wallet, rejects amount+fee > available balance. Places a real payout at the gateway's provider when one is configured |
| `/api/transactions/list.php` | GET | Filters: `type`, `status`, `from`, `to`, `search`, `sort`, `page`, `per_page` |
| `/api/support/conversations.php` | GET/POST | GET scoped to caller (or all, for staff); POST creates a conversation (customer) |
| `/api/support/messages.php` | GET/POST | `conversation_id` ownership enforced server-side (IDOR guard) |
| `/api/notifications/list.php` | GET | |
| `/api/notifications/mark-read.php` | POST | `{id}` or `{all:true}` |
| `/api/admin/users/list.php` | GET | Admin/operator only |
| `/api/admin/users/suspend.php` | POST | Admin only. Cannot suspend self or another admin |
| `/api/admin/users/reactivate.php` | POST | Admin only |
| `/api/profile/update.php` | POST | |
| `/api/settings/change-password.php` | POST | Requires current password |
| `/api/admin/gateways/list.php` | GET | Admin only |
| `/api/admin/gateways/create.php` | POST | Admin only. Full key never returned again after this call — only a display hash + last 4 chars |
| `/api/admin/gateways/update-status.php` | POST | Admin only. Blocks deactivating the current default |
| `/api/admin/gateways/set-default.php` | POST | Admin only. Must already be active |
| `/api/admin/gateways/rotate-key.php` | POST | Admin only |
| `/api/admin/gateways/delete.php` | POST | Admin only. Blocks deleting the current default |
| `/api/admin/gateways/set-webhook-secret.php` | POST | Admin only. Inbound provider webhook signing secret |
| `/api/webhooks/razorpay.php` | POST | Public (provider-authenticated via signature, not a session). `?gateway_id=` |
| `/api/webhooks/cashfree.php` | POST | Public (provider-authenticated via signature). `?gateway_id=` |
| `/api/webhooks/gateway.php` | POST | Public. Generic fallback receiver — placeholder signature scheme, see `includes/gateway_webhooks.php` |
| `/api/admin/users/api-ips.php` | GET | Admin only. A customer's API credentials + IP whitelist requests |
| `/api/admin/users/approve-api-ip.php` | POST | Admin only. Approves a pending IP whitelist request |
| `/api/admin/users/reject-api-ip.php` | POST | Admin only. Rejects a pending IP whitelist request |
| `/api/admin/users/remove-api-ip.php` | POST | Admin only. Revokes an already-approved IP |
| `/api/settings/request-api-ip.php` | POST | Customer only. Submits an IP whitelist request for review |
| `/api/auth/api-token.php` | POST | Public (client_key/secret_key authenticated). Exchanges credentials for a bearer token; IP-whitelist-gated |
| `/api/settings/api-credentials.php` | GET | Customer only. Auto-provisions client_key/secret_key and a webhook signing secret on first load |
| `/api/settings/rotate-api-secret.php` | POST | Customer only |
| `/api/settings/rotate-webhook-secret.php` | POST | Customer only. Signs outbound deliveries to the callback URLs below |
| `/api/settings/generate-api-token.php` | POST | Customer only |
| `/api/settings/save-api-webhooks.php` | POST | Customer only. Sets `payin_callback_url`/`payout_callback_url` |

| `/api/transactions/detail.php` | GET | Full transaction detail + event timeline for one PayIn/PayOut (`?id=`). Customer-scoped to their own; admin/operator can view any. Powers the "eye" view action on Transactions/PayIns/PayOuts. |

Full customer-integration walkthrough (auth exchange, pay-in/payout, outbound webhook payload + signature verification, error codes, downloadable Postman collection): in-app at **API documentation** (`/api-docs`, customer role). `/admin/gateways/docs` is a separate, admin-only page about configuring upstream providers (Razorpay/Cashfree) — not the customer integration reference.

## 12. What's been tested

This build was exercised end-to-end against a real MySQL instance (Docker) rather than only reviewed statically. Verified: login/logout/rate-limit, dashboard + wallet data for both roles, deposit and withdrawal creation (including fee math and insufficient-balance rejection), transaction filtering/search/sort/pagination, the full support-reply loop across two separate sessions (customer sends → admin inbox shows it → admin replies → customer's next fetch shows the reply → notification created), IDOR rejection on a second customer trying to read another customer's conversation, the admin suspend flow invalidating an already-logged-in customer session on its very next request (page nav and API call both verified), CSRF rejection on a token-less mutation, and role-based 403s on customer access to admin routes.

Not yet done, and worth doing before a real launch: a screen-reader pass, a formal WCAG audit tool run, and a dependency/penetration review — the code follows the practices in §10 but hasn't been adversarially tested beyond the IDOR/CSRF/suspension/rate-limit checks above.
