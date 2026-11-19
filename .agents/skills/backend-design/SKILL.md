---
name: backend-design
description: >-
  Design, implement, refactor, or review PawCariño backend architecture, PHP 8.3 controllers,
  services, models, MySQL 8.4 schemas and migrations, RBAC/business-unit isolation, database
  transactions, inventory/order/payment/OTP/appointment workflows, and isolated tests.
  Activate this skill whenever working on server-side PHP logic, SQL queries or migrations,
  authentication/authorization boundaries, or backend architectural decisions.
  Not for pure CSS styling or client-side visual design.
---

# PawCariño Backend Design & Architecture

Use this skill for all server-side design, PHP 8.3 implementation, MySQL 8.4 schema/migration work, domain workflow enforcement, and backend code review in PawCariño (formerly VETGRPET).

## Architectural Reality & Layering

PawCariño is a **frameworkless PHP 8.3 + MySQL 8.4 monolith** using PSR-4-style `App\` autoloading ([app/bootstrap.php](../../../app/bootstrap.php)), custom routing ([public/index.php](../../../public/index.php)), and PDO (`App\Core\Database::pdo()`). Do **not** introduce framework assumptions (Laravel, Symfony, ORM entities, Python/Node services, or microservices).

Respect strict inward dependency boundaries across the repository layout:

1. **Core Infrastructure (`app/Core/`):** `Database`, `Session`, `CSRF`, `Router`, `Controller`, `EnvConfig`. Owns request dispatch, session lifecycle, CSRF tokens, and shared RBAC/session gates.
2. **HTTP Controllers (`app/Controllers/`):** Thin interface adapters. A controller action should only:
   - Enforce CSRF (`CSRF::check()`) on POST routes and verify auth/RBAC/unit isolation (`requireCustomer()`, `requireStaff()`, `requireBusinessManager()`, `requireSystemAdmin()`, `requireSuperAdmin()`).
   - Parse and validate HTTP inputs.
   - Delegate multi-step business logic to a Service or Model.
   - Render a view or redirect. *(Note: `Controller::redirect()` reuses non-200 status codes, so 4xx/5xx statuses such as 403/419/429/503 must render directly instead of redirecting.)*
3. **Application Services (`app/Services/`):** Transaction boundaries, row/named locking (`SELECT ... FOR UPDATE`, `GET_LOCK`), state transitions, and Ports & Adapters integrations (e.g., `Payment\OnlinePaymentGatewayInterface` / `UnconfiguredOnlinePaymentGateway`, `MailService` `smtp`/`null` drivers, `OrderReservationService`, `OrderPaymentService`, `CheckoutHoldService`, `PosSaleService`, `CustomerRegistrationPendingService`, `InterfaceSwitcherService`).
4. **Domain & Data Models (`app/Models/`):** Focused invariants, lookup/unit resolution, audit logging, and isolated validation (`Units`, `Audit`, `Cart`, `InventoryBatch`, `AdminAccountService`, `AppointmentScheduleValidator`, `OtpService`).
5. **Database (`database/`):** Versioned SQL migrations under `database/migrations/NNN_*.sql` with matching `database/migrations/down/NNN_*.down.sql`. Keep `schema.sql` empty; migrations are the single source of truth.

## Mandatory Reference Consultation

Before designing, modifying, or reviewing backend code or database schemas, consult the relevant reference(s) under `references/`:

1. **Architecture Patterns, Ports & Adapters, DDD & Troubleshooting** — Read [references/architecture-patterns.md](references/architecture-patterns.md) when structuring service/repository boundaries, isolating external gateways or mailers behind interfaces, modeling bounded contexts/aggregates/value objects, writing database-free unit tests with in-memory/null adapters, or resolving layer/dependency leaks.
2. **PawCariño Architectural & Business Rules** — Read [references/pawcarino-architecture-and-rules.md](references/pawcarino-architecture-and-rules.md) before touching any domain workflow, authorization guard, table schema, or transaction. Covers the three business units, RBAC & session context isolation, inventory & OD-09 custody rules, sales/orders/payments/COD (OD-10 & OD-11), Customer Email OTP vs GCash/Maya OTP isolation, medical/grooming appointments & clinical records, UTC vs MySQL clock handling, migration runner mechanics, and test-database safety.

## Pre-Implementation Checklist

- **Business-Unit Isolation:** Verify unit scoping (`business_unit_id`) and email-authenticated employee session context (`base_context === 'employee'`, `active_context !== 'customer'`, `auth_method !== 'username'`). Never trust client-supplied unit/actor IDs without server verification.
- **Confirmed vs Unresolved Rules:** Implement only **CONFIRMED** rules; never guess or implement **UNRESOLVED** rules (such as the Rule 6 vs Rule 8 return-movement taxonomy) as approved requirements.
- **Atomic Transactions & Locking:** Wrap multi-row stock, reservation, payment, and registration transitions in `PDO::beginTransaction()` / `commit()` / `rollBack()` with explicit row locks (`FOR UPDATE`) or MySQL named locks (`GET_LOCK`) and `Audit::record()` inside the transaction.
- **No Secrets in Logs or Audits:** Never pass passwords, password hashes, OTPs, session tokens, SMTP credentials, or `OTP_HMAC_SECRET` into `Audit::log()`, `Audit::record()`, error pages, or `app.log`.
- **Safe Verification:** Run tests only against an isolated/disposable database (with `MAIL_DRIVER=null`), never against a live/preserved `vetgrpet_db` instance.
