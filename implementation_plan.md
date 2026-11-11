# Checkpoint 1: Repository verification and implementation plan

## Verified repository facts (from the current source)

| Area | Finding |
|---|---|
| VCS | `.git` exists (`HEAD` → `refs/heads/master`) but **no `git` binary is installed**, so I can't check working-tree status. I won't touch any file outside the feature scope. |
| Composer | **Not installed**, and there's no `composer.json` or `vendor/`. `vendor/` is already listed in `.gitignore`. |
| PHP | Bundled `tools/php83`, `date.timezone=UTC`. Loaded extensions include openssl, curl, pdo_mysql and mbstring. |
| MySQL | 8.4.13. `time_zone=SYSTEM` (+08:00), which **differs from PHP (UTC)**. Isolation level is REPEATABLE-READ. |
| DB grants | `vetgrpet_app` has privileges on `vetgrpet_db` only, so it can't create a disposable database. |
| Config | `config/database.php` reads only `DB_*` from `.env`. `.env` has no APP_ENV, mail or secret keys. |
| Migration runner | Splits on `;\n` and is **not transactional**. It records each file in `schema_migrations` after all of its statements succeed. Down files use `down/NNN_name.down.sql` (018 is an inconsistent exception). Applied: 001–018. |
| `user_account` | `utf8mb4_0900_ai_ci`. `email` and `username` (VARCHAR 100, nullable) are UNIQUE. `email_verified_at` is a nullable TIMESTAMP. |
| Collations | Mixed: 001/004 tables use `0900_ai_ci`, 011+ tables use `unicode_ci`. New tables will use `0900_ai_ci`, matching `user_account`. |
| Registration | `registerDetails()` validates input, then immediately inserts `user_account` and `customer`. A duplicate email returns 422 with a different message, which **enumerates accounts**. There's no audit entry on registration. The form doesn't render a `username` field, but the controller accepts one. |
| Invitations | `AdminAccountService` invitation flow inserts `email_verified_at = NULL` plus a customer row. The SysAdmin dashboard treats `employee AND email_verified_at IS NULL` as **Pending Invitation**. Confirmed: this must not be reinterpreted. |
| `Controller::redirect()` | Reuses any non-200 status, so 429/503 responses must be rendered rather than redirected. |
| Tests | All suites drive a live server on **:8080** that uses the `.env` database, and they seed or mutate it. The live dev server currently runs on **:8000**. `acceptance.php` and `admin_and_session_tests.php` register through `/register` and expect an immediate account, so they need OTP-aware updates. Acceptance asserts "Email not verified" for a fresh registration, which will change by design. |

## Key design decisions

1. **Clock**: all OTP, cooldown, rate-limit and expiry arithmetic uses MySQL `UTC_TIMESTAMP()` / `UNIX_TIMESTAMP()` in UTC `DATETIME` columns. PHP `time()` is never used. `email_verified_at = NOW()` follows the existing TIMESTAMP semantics. The shared connection's time zone is left unchanged so existing code isn't affected.
2. **Serialization**: MySQL named locks (`GET_LOCK`) keyed per email and per IP serialize rate-limit checks and verification. `SELECT … FOR UPDATE` on the pending row, plus the permanent UNIQUE keys, give the final duplicate protection.
3. **Non-enumeration**: for an already-registered email, no pending row is created and no OTP is issued. The session receives a *decoy* state that behaves the same on the verify, resend and change-email pages: same status codes, messages, attempt counters and DB-backed rate limits. Failed decoy attempts are also logged. An informational "account exists" email is sent, which keeps timing parity with real OTP sends.
4. **Send ordering**: (a) reserve the rate limit and invalidate the old OTP, then commit; (b) send the email; (c) store the new HMAC digest only if the send succeeded. A failed send therefore never leaves a usable OTP.
5. **Conflict recovery**: after a correct OTP, a username conflict marks the pending row `otp_verified_at` + `conflict_field='username'`. The user picks another username without needing a new OTP, capped at 10 attempts. An email conflict keeps the pending row and offers change-email, which requires a new OTP and goes through the rate limits.
6. **Config**: a new `EnvConfig` reads keys from `.env`, falling back to process env (same pattern as `APP_ENV`). The mail driver must be set explicitly. Registration **fails closed (HTTP 503)** until the `.env` contains a valid `MAIL_*` setup and an HMAC secret of at least 32 bytes.
7. **Disposable test stack**: a PowerShell harness starts a **separate throwaway MySQL instance** (fresh datadir in %TEMP%, port 3310) from the bundled binaries. It copies the app into a temp folder with its own test `.env` (null mail driver, random HMAC secret), runs migrations and seed, serves the copy on :8080, runs the suites, then removes everything. The main DB and the real `.env` are never touched.

## Non-blocking points to flag
- Composer will be downloaded into `tools/` (gitignored), and its SHA-256 will be checked against getcomposer.org. Deployment will then need `composer install`.
- Decoy state that started with a registered email keeps the validated profile and the password hash in the **server-side PHP session** for at most 30 minutes. This lets change-email behave the same way in both cases.
