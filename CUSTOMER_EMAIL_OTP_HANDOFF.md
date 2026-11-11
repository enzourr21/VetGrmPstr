# Customer Email OTP Verification — Implementation State & Handoff Document

**Document Version:** 1.1.0  
**Project:** VETGRPET (PHP 8.3 / MySQL 8.4)  
**Location:** `d:\VETGRPETproj\CUSTOMER_EMAIL_OTP_HANDOFF.md`  
**Generated At:** 2026-11-11T13:35:00+08:00  
**Current Phase:** End of Checkpoint 1 / Transition to Checkpoint 2  
**Implementation Safety Status:** Workspace clean, no code or database alterations committed in the local repository, real `.env` unmodified, ready for safe continuation.

---

## A. Original Architecture & Specifications

### 1. Architectural Scope & Objective
Implement customer email One-Time Password (OTP) verification for **new customer registrations only**. Permanent records in `user_account` and `customer` must **never** be created prior to successful verification of email ownership.

### 2. Core Architectural Decisions (Non-Negotiable)
- **Pending-Registration Model:** 
  - Registration details from `AuthController::registerDetails()` are validated against all existing validation rules.
  - Validated records are held in a temporary table (`customer_registration_pending`) alongside password hash, OTP hash, and verification timestamps.
  - No rows are created in `user_account` or `customer` until successful OTP verification.
  - Permanent unique constraints on `email` and `username` remain intact. No table rows are pre-allocated or reserved in permanent tables before verification.
  - On successful OTP verification, `user_account` and `customer` are inserted atomically within a single database transaction.
  - `email_verified_at` is set to `NOW()` upon permanent account creation.
  - The pending registration row is deleted immediately following successful account creation.
  - An audit entry is recorded in `audit_log` (`actor_user_id` = newly created account ID) without recording OTPs, passwords, or secrets.
  - The customer is logged in via `Session::login()` and redirected to `/profile`, preserving the existing user experience.

- **Existing Customers:**
  - `email_verified_at` is **not** backfilled for existing accounts.
  - Existing customers with `email_verified_at IS NULL` retain their current login, pet management, shopping, and appointment privileges.
  - No existing customer is forced through the OTP workflow.

- **Employee and Administrator Compatibility:**
  - Employee and administrator authentication flows are completely untouched.
  - `AuthController::loginPassword()` and `StaffController::login()` remain unmodified.
  - `ua.email_verified_at IS NULL AND ua.account_status = 'active'` specifically signifies a **Pending Invitation** in `AdminAccountService` and `SysAdminController` (Veterinary Clinic and Pet Store employee invitation tracking). This semantic must never be altered or reinterpreted globally.
  - Dual-identity employees logging in with username or switching contexts into the Customer interface must continue to function without regression.

- **Isolation from Existing OTP Service:**
  - The existing `App\Models\OtpService` and table `otp_verification` (used for GCash/Maya mobile payment number verification) are kept completely distinct and unmodified.

### 3. Approved OTP & Security Specifications

| Parameter | Specification |
|---|---|
| **OTP Format** | 6-digit numeric string with preserved leading zeroes: `sprintf('%06d', random_int(0, 999999))` |
| **Storage** | HMAC-SHA256 digest (`hash_hmac('sha256', $purpose . $pendingId . $otp, $secret)`) |
| **HMAC Secret** | Server-side environment variable (`OTP_HMAC_SECRET`), minimum 32 bytes. Fails closed (HTTP 503) if missing/invalid |
| **Comparison** | Timing-attack resistant `hash_equals()` |
| **OTP Expiration** | 5 minutes (300 seconds) |
| **Per-Code Attempts** | 5 attempts. Counter incremented before code evaluation under lock. Attempt 5 with correct code succeeds; 5th incorrect attempt invalidates OTP |
| **Resend Cooldown** | 60 seconds |
| **Email Send Limit** | 5 OTP sends per normalized email address per rolling 60 minutes |
| **IP Send Limit** | 5 OTP sends per IP address (`$_SERVER['REMOTE_ADDR']`) per rolling 60 minutes |
| **Cumulative Verification Failures** | 15 failed attempts per email per rolling 60 minutes across all resends, re-registrations, and code invalidations |
| **Pending Registration Lifetime** | 30 minutes, cleaned up opportunistically during registration requests |
| **Session Security** | Cryptographically random pending token (`bin2hex(random_bytes(32))`) held in session; table stores its SHA-256 hash. Session ID regenerated when entering verification and upon final account creation. `Cache-Control: no-store` on sensitive routes |
| **Non-Enumeration** | Duplicate registration for an already-registered email produces the identical outward response (redirect, status code, flash message) and a decoy session state to prevent user enumeration |
| **Email Correction** | `/register/change-email` requires valid session token, invalidates old OTP, enforces cooldowns and limits |
| **Username Conflict Recovery** | Handled via `otp_verified_at` timestamp column in `customer_registration_pending`. When an entered OTP is verified successfully, `otp_verified_at` is set. If account creation subsequently encounters a duplicate username collision, the pending record remains in a verified state, allowing the customer to provide a replacement username without requiring a second email OTP |
| **Timestamp & Timezone Handling** | Do not assume the MySQL connection timezone. The actual PDO connection timezone must be explicitly verified during implementation before finalizing timestamp handling. All calculations for OTP expiry, cooldown, and hourly caps must enforce consistent UTC handling |

### 4. Implementation Checkpoints
- **Checkpoint 1:** Repository verification, architecture confirmation, and implementation plan *(Complete)*.
- **Checkpoint 2:** Database and mail foundation (migration 019 + down, PHPMailer via Composer, MailService with SMTP/null drivers, `.env.example`, isolation guard) *(Pending local implementation)*.
- **Checkpoint 3:** Pending registration and OTP service (models, HMAC hashing, rate-limiting, named locks, transaction engine) *(Pending)*.
- **Checkpoint 4:** Controllers, routes, and UI (`EmailVerificationController`, `/register/verify`, resend, change-email, countdown, scenic rails) *(Pending)*.
- **Checkpoint 5:** Regression and security testing (throwaway database testing, null mailer, full test suite execution) *(Pending)*.
- **Checkpoint 6:** Final code audit, documentation, and handoff *(Pending)*.

---

## B. Actual Progress vs. Antigravity Task List

### Distinction Between Research/Planning and Local Workspace Implementation
The Antigravity task history displays progress steps performed during the preceding review session by Claude Web. It is critical to note that **Claude Web performed conceptual planning, repository probing, and research analysis only**. 
**No application code, migrations, Composer packages, or database structures were actually created or modified in the local workspace by Claude.**

The table below reflects the actual status of each task:

| # | Task / Progress Step | Status | Accomplished Work & Current State |
|---|---|---|---|
| **1** | **Write architecture proposal** | **COMPLETED (Planning)** | Fully mapped the pending-registration architecture, HMAC digest storage, database-backed rate-limit schemas, decoy session state for duplicate emails, and UTC time synchronization. |
| **2** | **Verify auth-path and invitation claims in the repo** | **COMPLETED (Research)** | Verified existing authentication in `AuthController.php`, `StaffController.php`, `AdminAccountService.php`, and `SysAdminController.php`. Confirmed that `email_verified_at IS NULL` is intentionally used to mark invitation-pending employees, and verified legacy customer compatibility. |
| **3** | **Writing review** | **COMPLETED (Planning)** | Compiled Checkpoint 1 review document and identified discrepancies between assumptions and actual repository state (missing Git CLI on PATH, missing Composer, MySQL time zone vs PHP UTC). |
| **4** | **Probe sandbox for PHP, MySQL, Composer and repo state** | **COMPLETED (Research)** | Probed bundled PHP 8.3 (`tools/php83`), MySQL 8.4.13 (`tools/mysql84`), verified PDO connectivity via `vetgrpet_app`, confirmed database user grants (limited to `vetgrpet_db`), verified port 3306 binding, and checked extension availability (`curl`, `openssl`, `mbstring`, `pdo_mysql`). |
| **5** | **Resolve the three pre-implementation review points** | **COMPLETED (Planning)** | Resolved: (1) Clock source: explicit PDO connection timezone check with UTC enforcement; (2) Concurrency: MySQL named locks (`GET_LOCK`) and row locking (`FOR UPDATE`); (3) Non-enumeration: uniform response + decoy session state. Documented in `implementation_plan.md`. |
| **6** | **Checkpoint 2: migration 019 + down, EnvConfig, MailService, Composer, docs** | **NOT STARTED (Local Workspace)** | Checkpoint 2 tasks appearing in Antigravity task history represent Claude Web's planning/exploration. **Zero files were created or modified locally.** No migration 019, `composer.json`, `vendor/`, `EnvConfig.php`, or `MailService.php` exist in the local project workspace. |
| **7** | **Checkpoint 2: verify migration chain, mail config, SMTP safety, isolation guard** | **NOT STARTED (Local Workspace)** | Dependent on Step 6. No local validation has occurred. |
| **8** | **Checkpoint 3: Pending-registration and OTP service** | **NOT STARTED** | Dependent on Checkpoint 2 foundation. |
| **9** | **Checkpoint 4, 5 & 6: Application integration, testing, regression, final verification** | **NOT STARTED** | Dependent on Checkpoint 3. |

---

## C. Exact Project Changes

### 1. Files Created
- `d:\VETGRPETproj\implementation_plan.md` (and metadata `.json`): Architectural plan and Checkpoint 1 verification summary.
- `d:\VETGRPETproj\CUSTOMER_EMAIL_OTP_HANDOFF.md`: This comprehensive handoff document.
- Brain scratch files:
  - `C:\Users\Administrator\.gemini\antigravity\brain\af5c24a3-4574-450e-a883-6e6a50a26a2b\scratch\inspect.php` (DB probe script).
  - `C:\Users\Administrator\.gemini\antigravity\brain\af5c24a3-4574-450e-a883-6e6a50a26a2b\scratch\read_transcript.php` (Transcript analyzer).

### 2. Files Modified or Deleted
- **None.** No application code, database files, configuration files, or public assets have been modified or deleted in the local project workspace.

### 3. Subsystem Implementation Status
- **Composer & PHPMailer:** Not yet installed. Composer binary is not in `tools/`; `composer.json`, `composer.lock`, and `vendor/` do not exist.
- **Migration 019 & Down Migration:** Not yet created. Next migration number is verified as `019`. Target table collation verified as `utf8mb4_0900_ai_ci` to match `user_account`. Down migration will follow standard convention `019_create_customer_registration_pending.down.sql`.
- **Proposed `customer_registration_pending` Schema (Updated):**
  - `pending_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
  - `session_token_hash VARCHAR(64) NOT NULL`
  - `email VARCHAR(255) NOT NULL` (indexed, non-unique)
  - `username VARCHAR(100) NULL`
  - `password_hash VARCHAR(255) NOT NULL`
  - `first_name VARCHAR(100) NOT NULL`
  - `middle_name VARCHAR(100) NULL`
  - `last_name VARCHAR(100) NOT NULL`
  - `phone VARCHAR(30) NULL`
  - `address_line1 VARCHAR(255) NULL`
  - `city VARCHAR(100) NULL`
  - `province VARCHAR(100) NULL`
  - `postal_code VARCHAR(20) NULL`
  - `otp_hash VARCHAR(64) NULL`
  - `otp_expires_at DATETIME NULL`
  - `otp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0`
  - `otp_verified_at DATETIME NULL DEFAULT NULL` *(Records successful OTP verification for username conflict recovery)*
  - `conflict_field VARCHAR(50) NULL DEFAULT NULL`
  - `created_at DATETIME NOT NULL`
  - `expires_at DATETIME NOT NULL`
- **MailService & EnvConfig:** Not yet created.
- **Isolation Guard:** Not yet created.

---

## D. Environment State

### 1. Runtimes & Dependencies
- **PHP Version:** PHP 8.3.0 (cli) (ZTS Visual C++ 2019 x64) located at `D:\VETGRPETproj\tools\php83\php.exe`.
  - Configuration: `D:\VETGRPETproj\tools\php83\php.ini`.
  - `date.timezone` = `UTC`.
  - Extensions active: `curl`, `openssl`, `mbstring`, `pdo_mysql`, `fileinfo`, `json`, `session`.
- **MySQL Version:** MySQL 8.4.13 Community Server x64 located at `D:\VETGRPETproj\tools\mysql84\mysql-8.4.13-winx64\bin\mysqld.exe`.
  - Configuration: `D:\VETGRPETproj\tools\mysql84\my.ini`.
  - Server Timezone: `SYSTEM` (+08:00).
  - Collation: `utf8mb4_0900_ai_ci`.
- **Timezone Verification Requirement:**
  - **Do not assume the connection timezone.**
  - During Checkpoint 2 implementation, the actual PDO session timezone must be explicitly queried via `SELECT @@session.time_zone, NOW(), UTC_TIMESTAMP()`.
  - The implementation must ensure consistent UTC handling (either by executing `SET time_zone = '+00:00'` on PDO connection initialization or using `UTC_TIMESTAMP()` for all datetime operations) so that PHP UTC timestamps and MySQL datetime records remain strictly aligned.
- **Git Binary:** Not present on system `PATH`. Repository tracking must be verified using filesystem timestamps and direct file inspections.

### 2. Database & Port Configuration
- **Main MySQL Instance (Port 3306):**
  - Database: `vetgrpet_db`.
  - User: `vetgrpet_app`.
  - Privileges: Limited to `vetgrpet_db` (cannot execute `CREATE DATABASE`).
  - Current status: Inactive (process stopped following server restart).
  - Command to start main instance:
    ```powershell
    tools\mysql84\mysql-8.4.13-winx64\bin\mysqld.exe --defaults-file=tools\mysql84\my.ini --console
    ```
- **Temporary MySQL Instance (Port 3310):**
  - Planned for isolated testing in `%TEMP%\vetgrpet_test_mysql`.
  - **Current status: Not running, not yet created.** No files or directories exist in `%TEMP%` for port 3310.
- **Web / Development Server:**
  - Neither port 8000 nor port 8080 is currently listening.
  - Command to run dev server:
    ```powershell
    tools\php83\php.exe -S 127.0.0.1:8080 -t public public\index.php
    ```

### 3. Secrets and `.env` Integrity
- The live `.env` file at `d:\VETGRPETproj\.env` is **intact, unmodified, and preserved**.
- Credentials have not been exposed or logged.
- `.env.example` remains at its initial state and will be updated in Checkpoint 2 with placeholder keys only.

---

## E. Verification History & Findings

### 1. Commands Executed
1. `tools\php83\php.exe scratch\inspect.php` — Connected to `vetgrpet_db` using `vetgrpet_app` credentials; confirmed 18 migrations applied, inspected `user_account` schema, and verified invitation employee counts.
2. Network connectivity check to `repo.packagist.org` — Verified PHP `openssl` and `curl` can access external repositories (`NET OK`).
3. Port and process probes via PowerShell (`Get-NetTCPConnection`, `Get-Process`) — Identified all active and inactive processes.
4. Acceptance test suite inspection (`tests/acceptance.php`, `tests/admin_and_session_tests.php`) — Mapped customer registration expectations and test assertion dependencies.

### 2. Verified Baseline Facts
- `schema_migrations` contains 18 applied migrations (`001_initial_schema.sql` through `018_add_max_appointment_overlap_to_business_settings.sql`).
- `user_account` table contains 16 rows (12 currently have `email_verified_at IS NULL`, of which 7 are active employee invitation records).
- All existing migrations use `;\n` delimiter splitting and execute sequentially without transactional DDL.
- Registration currently creates `user_account` and `customer` immediately; duplicate emails currently return HTTP 422 with an explicit failure message that reveals email existence.

### 3. Known Constraints & Workarounds
1. **Connection Timezone Verification:** Probed MySQL system timezone is Malay Peninsula Standard Time (+08:00) while PHP operates in UTC. Implementation must verify actual PDO connection timezone via query and enforce UTC for all OTP expiration, cooldowns, and audit logs.
2. **Missing `CREATE DATABASE` Privilege:** The `vetgrpet_app` user cannot create disposable test databases on port 3306. Test automation will launch a separate temporary `mysqld` process with its own datadir on port 3310 for destructive test runs.
3. **No Git CLI:** File tracking must be managed through filesystem directory scans.

---

## F. Exact Next Actions

### Step 1: Complete Checkpoint 2 (Database & Mail Foundation)
1. **Create Migration 019 & Down Migration:**
   - File: `database/migrations/019_create_customer_registration_pending.sql`.
   - File: `database/migrations/down/019_create_customer_registration_pending.down.sql`.
   - Structures to create:
     - `customer_registration_pending`: Columns `pending_id`, `session_token_hash`, `email`, `username`, `password_hash`, `first_name`, `middle_name`, `last_name`, `phone`, `address_line1`, `city`, `province`, `postal_code`, `otp_hash`, `otp_expires_at`, `otp_attempts`, `otp_verified_at`, `conflict_field`, `created_at`, `expires_at`. Use collation `utf8mb4_0900_ai_ci`. Non-unique index on `email`.
     - `email_otp_send_log`: Columns `log_id`, `email`, `ip_address` (VARCHAR 45), `sent_at`. Indexes on `(email, sent_at)` and `(ip_address, sent_at)`.
     - `email_otp_failure_log`: Columns `failure_id`, `email`, `ip_address`, `failed_at`. Index on `(email, failed_at)`.
2. **Set up Composer & PHPMailer:**
   - Download official `composer.phar` to `tools/` with SHA-256 verification.
   - Create `composer.json` requiring `phpmailer/phpmailer: ^6.9`.
   - Run `tools\php83\php.exe tools\composer.phar install --no-dev --optimize-autoloader`.
   - Verify `vendor/` is ignored by `.gitignore` (already confirmed ignored).
   - In `app/bootstrap.php`, include `require APP_ROOT . '/vendor/autoload.php';` if it exists.
3. **Implement Environment Configuration Helper & Explicit PDO Timezone Verification:**
   - Create `app/Core/EnvConfig.php` to securely parse environment variables from `.env` and `getenv()` without modifying `.env`.
   - Explicitly verify the actual PDO session timezone upon database connection before finalizing timestamp operations. Ensure consistent UTC handling across PHP and MySQL.
4. **Implement MailService:**
   - Create `app/Services/MailService.php` with drivers:
     - `smtp`: Authenticated TLS/STARTTLS via PHPMailer.
     - `null`: Discards messages cleanly for testing/dev.
   - Enforce fail-closed behavior on missing or invalid configurations.
   - Create HTML and plain-text OTP email templates (OTP must never appear in email subject).
5. **Update `.env.example`:**
   - Add placeholder keys for `MAIL_DRIVER=null`, `MAIL_HOST=`, `MAIL_PORT=587`, `MAIL_USERNAME=`, `MAIL_PASSWORD=`, `MAIL_ENCRYPTION=tls`, `MAIL_FROM_ADDRESS=`, `MAIL_FROM_NAME=`, and `OTP_HMAC_SECRET=`.
6. **Implement Test Isolation Guard:**
   - Ensure tests fail immediately if pointed at production or unapproved database instances.

### Step 2: Transition to Checkpoint 3 (Service Layer)
- Create `app/Services/CustomerRegistrationPendingService.php` to encapsulate:
  - Six-digit OTP generation preserving leading zeroes: `sprintf('%06d', random_int(0, 999999))`.
  - Pending row creation and HMAC storage.
  - Recording `otp_verified_at` upon successful verification.
  - Username-conflict recovery permitting alternate username selection without re-issuing an OTP.
  - Attempt counting under MySQL named locks (`GET_LOCK`).
  - Atomic account provisioning.

---

*Handoff updated and verified against live workspace state.*
