# VETGRPET — Implement Customer Email OTP Verification

You are Sonnet 5.5 Implement the approved customer email OTP verification feature in the existing VETGRPET project.

You are responsible for implementation, testing, and reviewing your own changes. Work carefully and prioritize correctness, security, and compatibility with the existing application over implementation speed.

## 1. Project context and objective

VETGRPET is an existing PHP/MySQL application containing three business units:

- Veterinary Clinic
- Grooming Services
- Pet Store

Implement email OTP verification for **new customer registrations only**.

A customer must successfully verify ownership of their email address before a permanent `user_account` and corresponding `customer` record are created.

Use a **pending-registration architecture**. Do not create an unverified permanent account and attempt to protect it afterward.

The existing application has:
- PHP 8.3 and MySQL 8.4.
- A front controller and custom Router.
- Existing authentication, sessions, role guards, and customer registration.
- An existing `email_verified_at` column in `user_account`.
- An existing `OtpService` and `otp_verification` table for GCash/Maya OTP functionality.
- Numbered database migrations and a migration runner.
- Existing acceptance and administrator/session test suites.

Earlier code inspections are useful background, but **inspect the current repository and verify all relevant details yourself before modifying anything**.

## 2. Non-negotiable architectural decisions

### Registration

1. Validate registration input using the existing validation rules.
2. Do not immediately insert into `user_account` or `customer`.
3. Create a temporary `customer_registration_pending` record containing the validated registration information and password hash.
4. Generate and email an OTP.
5. Allow the customer to verify the OTP.
6. Only after successful verification, create `user_account` and `customer` together in one database transaction.
7. Set the new account's `email_verified_at = NOW()` when creating the permanent account after successful verification.
8. Delete the pending-registration record after successful account creation.
9. Log the successful verification in `audit_log` without recording the OTP, password, or other secrets.

Do not reserve email addresses or usernames in permanent account tables before verification. Permanent unique constraints must remain in place.

### Existing customers

- Do not backfill `email_verified_at`.
- Do not mark existing customers as verified without proof.
- Existing customers with `email_verified_at IS NULL` must retain their current login and access behavior.
- Do not force existing customers through the new registration OTP workflow.
- An optional future "verify existing email" feature is outside this implementation.

### Employee and administrator compatibility

Do not change the behavior of:
- Employee authentication.
- Administrator authentication.
- Employee invitation handling.
- Existing role guards.
- Existing customer login paths.

In particular, `email_verified_at IS NULL` has existing significance for invitation-pending employee accounts. Do not reinterpret it globally or introduce a rule that locks out employees who also have a customer record.

Do not modify `AuthController::loginPassword()` or `StaffController::login()` unless your own inspection proves a change is absolutely necessary. If a change is necessary, explain the reason before making it and add regression tests.

### Existing OTP functionality

Do not reuse, modify, or break:
- `OtpService`
- `otp_verification`
- Existing GCash/Maya OTP workflows

Create a separate email-verification implementation.

## 3. OTP and security requirements

Use these approved initial settings:

| Setting | Requirement |
|---|---|
| OTP format | 6-digit numeric code |
| Generation | `random_int()` |
| OTP expiration | 5 minutes |
| Attempts per OTP | 5 |
| Resend cooldown | 60 seconds |
| Send limit | 5 per email per rolling hour |
| IP send limit | 5 per IP per rolling hour |
| Total failed verification limit | 15 per email per rolling hour, enforced across resends and re-registration |
| Pending registration lifetime | 30 minutes |

A correct OTP submitted on the fifth attempt must succeed. The fifth incorrect attempt must invalidate the OTP.

Increment the attempt counter before comparing the submitted code, while holding the appropriate database lock. Ensure the correct fifth attempt is still accepted.

### OTP storage

- Store an HMAC-SHA256 digest, never plaintext OTP.
- Include a purpose string, pending-registration ID, and OTP in the HMAC input.
- Require a server-side HMAC secret of at least 32 bytes.
- Fail closed if the secret is missing or invalid.
- Use `hash_equals()` for comparison.
- Never log or expose OTP values or HMAC secrets.
- Never put the OTP in the email subject.

### Rate limiting

Rate limits must be database-backed, not session-only.

Use an append-only send log and, where needed, a persistent verification-attempt log or equivalent design.

Important:
- Deleting or replacing a pending registration must not reset send limits or failed-attempt limits.
- Resending resets the per-code attempt counter but not the cumulative failure limit.
- Re-registering with the same email must not bypass cooldowns or hourly caps.
- Record the normalized email and IP address consistently.
- Use `REMOTE_ADDR` for IP identification unless the project has an explicitly configured and trusted proxy arrangement. Never trust arbitrary forwarded IP headers.

Do not silently weaken these limits to simplify implementation.

### Session security

- Generate a cryptographically random pending-session token.
- Store only its hash in the database.
- Bind the pending registration to the token held in the user's session.
- Verify ownership of the pending row on every verification, resend, and change-email request.
- Regenerate the PHP session ID when entering the verification flow and after successful verification.
- Do not use `pending_id` alone as authorization.
- Set `Cache-Control: no-store` on verification pages and sensitive responses.
- Do not expose pending registration details to another session.

### Concurrency and account creation

Handle simultaneous requests safely.

- Use transactions and `SELECT ... FOR UPDATE` where appropriate.
- Verification, permanent account creation, audit logging, and pending-row deletion must be handled consistently.
- Two simultaneous verification requests for the same pending registration must not create two accounts.
- Two separate pending registrations for the same email must not create duplicate accounts.
- Keep the permanent unique constraints on email and username.
- Handle duplicate-key errors gracefully, including username conflicts.
- Never assume locking one pending row protects another pending row with the same email.

If a unique conflict occurs during verification, do not lose the user's pending information unnecessarily. Provide a safe recovery path to correct the conflicting registration information without bypassing rate limits.

## 4. Duplicate email and email correction

### Duplicate email handling

The public registration response must not reveal whether an email is already registered.

Use the same outward response for registered and unregistered email addresses.

For an already-registered email:
- Do not create a pending registration or send an OTP.
- An informational email explaining that an account already exists may be sent.
- Do not expose account existence through different HTTP status codes, redirects, response text, or obvious timing differences.

Do not add an email-existence check to a public email-check endpoint.

### Email correction

Provide a safe way to correct a mistyped email address before verification.

- Require the existing session-bound pending-registration token.
- Verify that the pending row belongs to that session.
- Invalidate the previous OTP.
- Update or replace the pending registration safely.
- Preserve send and verification limits.
- Apply the normal cooldown and hourly caps before sending a new OTP.
- Never allow changing an email through a pending ID alone.

Also provide a recovery path for username conflicts discovered at verification. Do not silently discard a pending registration or create an account with an unintended username.

## 5. Database implementation

Create migration 019 for the pending-registration and email OTP rate-limit storage.

Inspect the actual migration runner, existing table definitions, indexes, foreign-key conventions, engines, and collations before writing SQL.

The proposed structures are:

### `customer_registration_pending`

Include:
- `pending_id`
- `session_token_hash`
- `email` (indexed, not unique)
- `username` (nullable if the existing registration rules permit it)
- `password_hash`
- All validated registration fields currently collected by `registerDetails()`: first name, middle name, last name, phone, address line 1, city, province, and postal code
- `otp_hash`
- `otp_expires_at`
- `otp_attempts`
- Any necessary cumulative-attempt tracking, if not handled by a separate log
- `created_at`
- `expires_at`

Use a non-unique email index. Multiple pending registrations must not overwrite each other or allow one person to invalidate another person's session merely by registering the same email.

### `email_otp_send_log`

Include:
- `log_id`
- normalized `email`
- `ip_address` (`VARCHAR(45)`)
- `sent_at`
- Indexes supporting email/time and IP/time rate-limit queries.

Add a persistent failed-verification event log or an equivalent database-backed mechanism if necessary to enforce the 15-failure rolling-hour limit across re-registration.

Use the `user_account` collation where appropriate. Inspect the actual schema rather than assuming all existing tables use the same collation.

### Migration rules

- Migration 019 must contain table creation only.
- Do not backfill or update existing `user_account` rows.
- Do not alter `user_account` merely to implement this feature.
- Provide a matching down migration using the repository's actual naming convention.
- Inspect how the migration runner records successful migrations and handles failures.
- Avoid combining non-transactional DDL with unrelated data updates.
- Do not execute destructive migrations against the main database without explicit authorization.

Use consistent UTC timestamp handling and one clock source for OTP expiration, cooldown, and rate-limit calculations. Inspect the actual MySQL connection timezone behavior and configure it explicitly if needed. Do not mix PHP and MySQL clocks without conversion.

## 6. Email delivery

Use PHPMailer installed through Composer.

Create a mail service with two drivers:

- `smtp`: sends real email through configured SMTP.
- `null`: drops messages without sending or logging their contents.

Requirements:
- Tests must use the `null` driver.
- Development/testing must not accidentally send real emails.
- Validate mail configuration at startup or before sending.
- SMTP must use authenticated transport and proper TLS certificate verification.
- Keep SMTP credentials and the HMAC secret in environment configuration.
- Never edit the actual `.env` file.
- Update `.env.example` with placeholder configuration only.
- Do not hardcode real credentials or secrets.
- Do not log email bodies, OTP values, passwords, or secrets.
- Provide a clear, safe response when email delivery fails. The customer must not be treated as verified.
- Ensure a failed send does not leave an apparently usable OTP.
- Do not silently fall back from SMTP to a non-secure transport.

Include a simple HTML and plain-text email template. Keep the OTP out of the subject.

Document the required SMTP configuration and note that production deliverability requires a correctly configured sender domain, SPF, and DKIM. Do not claim these are configured if they are not.

## 7. Application implementation

Inspect the current routes, controllers, views, session helpers, validation, CSRF handling, Composer setup, and frontend conventions.

Implement the feature using the project's existing architecture.

Expected components include:
- A pending-registration / email OTP service under `app/Models` or the project's appropriate service location.
- A mail service.
- An email verification controller.
- A verification view.
- Registration and verification routes.
- A matching migration and down migration.
- Required frontend changes.
- Tests and documentation.

Modify `AuthController::registerDetails()` only after its existing validation logic. Preserve the current registration fields, validation rules, password hashing approach, and successful customer `Session::login()` payload.

Move permanent account creation into the successful OTP verification flow.

Add:
- A resend countdown.
- A clear expiration message.
- A safe change-email action.
- A recoverable username-conflict path.
- An OTP input with `autocomplete="one-time-code"` and `inputmode="numeric"`.
- Server-side validation regardless of frontend behavior.
- `CSRF::check()` on every POST route.

Use the existing routing and view conventions. Do not introduce a framework or unrelated architectural refactor.

Throttle responses should be rendered with the appropriate status code, such as HTTP 429, rather than redirecting through `Controller::redirect()` if that helper reuses a previous response status.

## 8. Pending data cleanup and privacy

Pending registrations contain personal information and password hashes.

- Enforce the 30-minute pending-registration expiration.
- Clean expired pending rows opportunistically during appropriate registration requests, since the project does not currently have a cron-based cleanup system.
- Define and document a retention period for send and failed-attempt logs.
- Ensure cleanup does not remove active pending registrations.
- Ensure cleanup never removes permanent customer records.
- Avoid retaining unnecessary personal information in rate-limit logs. Prefer normalized email and IP only where required.

## 9. Testing requirements

Inspect the existing test suites before changing them. Use a disposable test database and the null mail driver. Never send real emails during tests.

Do not add production-only OTP retrieval endpoints or weaken security to make tests easier.

Test OTP behavior through controlled PDO setup, including writing a known HMAC digest using the configured test secret.

### Registration and OTP tests

Test:
- Valid registration creates a pending registration but no permanent account.
- Invalid registration input is rejected.
- Duplicate registered email receives a non-enumerating response.
- Correct OTP creates exactly one permanent customer account.
- New permanent customer has `email_verified_at` set.
- Incorrect OTP is rejected.
- Correct OTP on attempt five succeeds.
- Fifth incorrect attempt invalidates the code.
- Expired OTP is rejected.
- Reused OTP is rejected.
- Resend invalidates the previous OTP.
- Resend cooldown is enforced.
- Email and IP hourly send caps are enforced.
- Total failed-attempt cap survives resends and re-registration.
- Pending registration expires and is cleaned up.
- Email correction invalidates the old OTP.
- Username conflicts are handled without losing the user's ability to recover.
- Missing or invalid HMAC secret fails closed.
- Failed email delivery never verifies an account.
- A session cannot access another session's pending registration.
- A pending ID without the correct session token is insufficient authorization.
- Two parallel verification requests for one pending registration create exactly one account.
- Two separate pending registrations for the same email cannot create duplicate accounts.
- Rate limits cannot be bypassed by creating another pending registration.

### Employee and administrator regression tests

Verify that all existing behaviors remain intact:
- Employee email login.
- Employee username login.
- `login_context=customer`.
- `enterCustomerInterface`.
- Demo super-admin login.
- `/staff/login`.
- Existing customer login.
- Legacy customer with NULL `email_verified_at` can still sign in.
- Newly verified customer can sign in.
- Invitation-pending employee accounts retain NULL `email_verified_at`.
- Invitation-pending employees still appear in the System Administrator pending-invitation list.
- Invitation-pending employee username login remains unchanged.

Run the existing administrator/session, navigation, and acceptance test suites. Preserve their existing assertions unless a change is demonstrably necessary and documented.

Do not assume a test passes merely because it executes. Inspect failures and distinguish feature defects from existing unrelated failures.

## 10. Explicitly out of scope

Do not implement:
- System Administrator employee invitation links.
- Invitation acceptance or password-setting workflows.
- Email verification for existing customers.
- Changes to employee/admin authentication.
- Changes to GCash/Maya OTP.
- Unrelated Receptionist workspace fixes.
- Unrelated database or UI refactoring.
- Changes to the real `.env`.
- Changes to existing customer access policy.

If you discover unrelated security concerns, report them separately. Do not silently expand this task.

## 11. Implementation checkpoints

Work in these stages. At the end of each stage, inspect your changes and run the relevant checks before proceeding.

**Checkpoint 1 — Repository verification and implementation plan**
- Inspect the current branch, working tree, and repository state.
- Verify the current registration flow, schema, migration runner, Composer setup, sessions, routing, mail configuration, and test structure.
- Confirm invitation account behavior and the `email_verified_at` semantics.
- Identify any differences from the earlier Sonnet reports.
- Report the implementation plan and any blocking ambiguity before making changes.

**Checkpoint 2 — Database and mail foundation**
- Implement migration 019 and its down migration.
- Add PHPMailer and the mail service with SMTP/null drivers.
- Update `.env.example` and documentation.
- Verify migration structure and mail configuration.
- Do not edit the actual `.env`.

**Checkpoint 3 — Pending-registration and OTP service**
- Implement secure pending-registration creation, OTP issuance, resend, verification, rate limiting, expiry, session binding, cleanup, and transactional account creation.
- Test service behavior before integrating the UI.

**Checkpoint 4 — Controller, routes, and frontend**
- Integrate registration with pending-registration.
- Add verification, resend, and change-email flows.
- Add the OTP UI, countdown, and recoverable error handling.
- Preserve the existing successful login behavior after verification.

**Checkpoint 5 — Regression and security testing**
- Run the new OTP tests.
- Run all relevant existing test suites.
- Verify employee, administrator, invitation, and legacy customer compatibility.
- Review concurrency, duplicate registration, rate-limit bypasses, session binding, and secret handling.

**Checkpoint 6 — Final review and handoff**
- Review every changed file for mistakes, security weaknesses, and unrelated changes.
- Check migrations and rollback instructions.
- Update documentation.
- Report exact files created and modified, migration instructions, required environment variables, test commands, test results, and any unresolved limitations.

Do not report a checkpoint as complete until its checks have actually passed or its limitations are explicitly documented.

## 12. Working rules

- You are the sole implementation agent for this task. Do not delegate to Gemini or introduce another coding agent.
- Do not assume earlier reports are correct if the current source contradicts them.
- Do not overwrite unrelated user changes or discard uncommitted work.
- Avoid unnecessary file rewrites and unrelated refactoring.
- Do not expose secrets, OTP values, password hashes, or personal information in logs or reports.
- Do not run destructive commands or destructive migrations against the main database.
- Do not claim tests passed unless you actually ran them.
- If a requirement conflicts with the existing architecture, stop and explain the conflict instead of silently choosing a risky workaround.

**Final objective:** Deliver a secure, tested customer email verification workflow in which a permanent customer account is created only after successful email OTP verification, while preserving all existing employee, administrator, invitation, and legacy customer behavior.

You are free to review the architecture-patterns under skills\backend-design