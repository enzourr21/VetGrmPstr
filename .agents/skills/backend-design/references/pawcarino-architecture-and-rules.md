# PawCariño Architectural & Business Rules Reference

Authoritative summary of PawCariño's verified system architecture, domain boundaries, confirmed business rules (including Owner Clarifications **OD-09**, **OD-10**, **OD-11**), and unresolved boundaries.

> **Authority Rule:** Items marked **CONFIRMED** are binding. Items marked **UNRESOLVED / NOT APPROVED** in `database/BUSINESS_RULES.md` or `PAYMENT_GATEWAY_ARCHITECTURE_SPEC_REVISED.md` must **not** be invented or implemented as approved rules.

---

## 1. Business Units & Structural Boundaries

- **Three Business Units (`App\Models\Units`):**
  1. `Pet Store` (`business_unit_id = 1`)
  2. `Veterinary Clinic` (`business_unit_id = 2`)
  3. `Grooming Services` (`business_unit_id = 3`)
- **Separation:** Shared identity tables (`user_account`, `customer`, `employee`) span units, while unit operations, products/prices, inventory, appointments, sales, orders, and clinical/grooming records remain separated by `business_unit_id`.
- **Branches:** Multiple `branch` rows per business unit are supported (`Units::mainBranchId()`). Currently `inventory` is linked **1:1** with `business_product` (`UNIQUE(business_product_id)`) — do **not** assume per-branch inventory splits (`inventory.branch_id`) unless explicitly approved.

---

## 2. Identity, Session Context & RBAC Isolation

- **Customer vs Employee Separation:** Customer and employee are separate business concepts. One `user_account` may link to a `customer` row, an `employee` row, or both.
- **Walk-In vs Order Customer:** Walk-in `sale.customer_id` may be `NULL`. `customer_order.customer_id` is required.
- **Strict Unit-Scoped RBAC (`account_role`):**
  - `account_role` is scoped by `business_unit_id`. A role in one unit grants **zero** access to another unit.
  - **Even `SYSTEM_ADMINISTRATOR` is unit-scoped**, never global (`Core::requireSystemAdmin()` enforces unit isolation against `requestedUnitId`, `$_GET['unit_id']`, and `$_POST['business_unit_id']`).
  - Only `SUPER_ADMINISTRATOR` (`ua.is_super_admin = 1` or `SUPER_ADMINISTRATOR` role) has cross-unit authority.
  - *Position* = job title; *Role* = permission bundle; *Permission* = individual capability (`role_permission`).
- **Session & Context Separation (`app/Core/Core.php`, `InterfaceSwitcherService`):**
  - Protected employee/admin endpoints (`requireSuperAdmin()`, `requireSystemAdmin()`, `requireBusinessManager()`, `requireStaff()`) must verify:
    - Active `user_account` (`account_status = 'active'`) and active `employee` (`employee_status = 'active'`)
    - `base_context === 'employee'`
    - `active_context !== 'customer'` (when switched into the Customer interface, employee privileges are blocked)
    - `auth_method !== 'username'` (admin/staff endpoints require email-authenticated sessions)
  - `requireCustomer()` supports both customers and authenticated employees/Super Admin browsing as a customer (auto-provisioning an active `customer` profile if absent) while re-checking active status on every protected request.
- **Pending Invitation Semantic (Do Not Alter):** `ua.email_verified_at IS NULL AND ua.account_status = 'active'` on an employee account means **Pending Invitation** in `AdminAccountService` and `SysAdminController`. Never globally backfill `email_verified_at` or reinterpret `NULL` as unverified customer blocking.
- **Clinical & Operational Role Boundaries:**
  - **Veterinarian:** consultation, assessment, diagnosis, treatment planning, prescriptions, medical records, finalization/signing; may add clinically necessary services only after recording customer consent (decision, actor, timestamp).
  - **Nurse:** administers authorized treatments/medications, documents nursing work, may confirm an already requested/authorized service was performed; **cannot** independently add billable services.
  - **Technician:** technical/diagnostic processing; must not independently interpret results or make treatment decisions outside scope.
  - **Receptionist:** customer administration and appointment/order/remittance coordination; no unrestricted clinical-record access.
  - **Business Manager:** operational management in assigned unit; read-only access to medical records; does not edit employee profiles/evaluations.
  - **System Administrator:** accounts, roles, and positions within assigned unit; does not confer clinical or operational authority.

---

## 3. Product Catalog, Inventory & OD-09 Custody Lifecycle

- **Catalog Separation:** `product` holds global catalog data; `business_product` (`UNIQUE(business_unit_id, product_id)`) holds unit-specific price, active status, and `requires_batch_tracking`. Product status is independent of stock quantity.
- **Derived Available Formula (OD-09):**
  - `Available = (On Hand + In Transit) - Committed` (equivalently `On Hand - (Committed - In Transit)`).
  - Never store `Available` as an independent column. Enforce nonnegative quantities and `Committed >= In Transit`.
- **Batch & FEFO Tracking:**
  - Non-batch-tracked (`requires_batch_tracking = 0`): `inventory.on_hand` is physical stock; no `inventory_batch` rows.
  - Batch-tracked (`requires_batch_tracking = 1`): physical stock lives in `inventory_batch.quantity` (`inventory.on_hand` is the aggregate) and allocates via **FEFO** (earliest-expiring eligible batch first, splitting across batches when needed).
- **Reservation Timing:**
  - Carts (`cart` / `cart_item`) and unconfirmed `pending` orders do **not** reserve stock.
  - Store confirmation (`pending -> confirmed`) atomically validates availability, creates `inventory_reservation` rows, and increases `inventory.committed`.
  - Partial fulfillment is supported; cancellation releases only unfulfilled committed quantity.
- **OD-09 Delivery Custody & Return Inspection:**
  - **Pickup:** reduces `on_hand`/batches and releases `committed` only at actual customer handover.
  - **Delivery Dispatch:** reduces `on_hand`/batches and increases `in_transit`, leaving `committed` unchanged while in courier custody.
  - **Confirmed Delivery:** reduces `in_transit` and releases `committed` — **never** deduct `on_hand` or batches a second time.
  - **Failed Delivery / Physical Return:** does **not** automatically release `committed` or restore `on_hand`. Physically returned stock stays quarantined in `in_transit` / `committed` (`returned_pending_inspection`) until inspected; only inspected sellable quantity may return to `on_hand`/batches; damaged/lost stock is never sellable.
- **Inventory Movements:** Every stock change writes an `inventory_movement` row (outbound negative, inbound positive; `sale_item_id` and `reservation_id` mutually exclusive; voided walk-in sales create positive `sale_void` reversals without editing/deleting originals) atomically within the same DB transaction.
- **UNRESOLVED Boundary (Rule 6 vs Rule 8):** Disputed physical-return movement taxonomy (`sale_refund` vs `return_sellable`/`return_damaged`) and accounting treatment are **not approved** — do not invent or implement them without explicit schema/ERD approval.

---

## 4. Sales, Orders, Payments, Refunds & COD (OD-10 & OD-11)

- **Separate Transaction Parents:** `sale` (walk-in POS), `customer_order` (online customer orders), and `service_invoice` (`UNIQUE(appointment_id)`) are separate. Every `payment` row belongs to **exactly one** parent (`invoice_id`, `sale_id`, or `order_id`).
- **Separate Checkouts per Business Unit:** Online carts/checkouts are separated by business unit (`business_unit_id`).
- **Monetary Refunds vs Physical Returns:** Separate events (one can happen without the other). A refund is a new `payment` row (`payment_type = 'refund'`, `original_payment_id` referencing the preserved original). Total refunds must never exceed the original payment amount.
- **OD-10 (COD Collection & Remittance Separation):**
  - Customer COD collection and courier-to-store cash remittance are separate lifecycle stages.
  - On courier delivery + COD collection, `payment` becomes `completed` (`collection_channel = 'courier_cod'`, `collected_by_employee_id`, `collected_at`) for full `total_amount` (`subtotal_amount + delivery_fee`) and **never** reverts to owing based on remittance state.
  - The courier remits only `subtotal_amount` (excluding `delivery_fee`) via `courier_cod_remittance` and `courier_cod_remittance_allocation` (`handover_status`: `submitted` → `completed`; `verification_status`: `pending` → `verified`).
- **OD-11 (Phase 1 Refund Scope):** Pre-fulfillment partial refunds are excluded in Phase 1; use the full-refund approval workflow. Approval is not itself proof that funds have been returned.

---

## 5. Customer Email OTP vs Payment OTP

- **Strict Isolation:** Customer signup email verification (`CustomerRegistrationPendingService`, table `customer_registration_pending`) is completely separate from GCash/Maya mobile payment OTP (`App\Models\OtpService`, table `otp_verification`). Never merge or alter `OtpService` for email registration.
- **Pending-Registration Invariant:** Never create `user_account` or `customer` rows before email OTP verification succeeds. Create both + delete pending row + write `Audit::record()` atomically inside one DB transaction, setting `email_verified_at = NOW()`. Existing customers with `email_verified_at IS NULL` retain full access.
- **Cryptographic & Rate-Limit Rules:**
  - 6-digit numeric OTP preserving leading zeroes (`sprintf('%06d', random_int(0, 999999))`).
  - HMAC-SHA256 digest using `OTP_HMAC_SECRET` (>= 32 bytes; fail closed HTTP 503 if missing/weak); verify with `hash_equals()`. Never store plaintext OTPs.
  - 5-minute expiry, 5 attempts per code (incremented under lock before evaluation), 60-second resend cooldown, 5 sends/60m per email and per IP, 15 cumulative failures/60m, 30-minute pending lifetime.
  - Serialize checks with MySQL `GET_LOCK` + `SELECT ... FOR UPDATE`.
  - Non-enumeration: duplicate registration on an existing email yields identical outward HTTP status, redirect, flash message, and decoy session behavior.
- **UTC Clock Rule:** PHP runs in `UTC` while local MySQL `time_zone` may be `SYSTEM` (`+08:00`). Never mix PHP `time()` with MySQL timestamps for OTP expiry/cooldown arithmetic — use `UTC_TIMESTAMP()` / `UNIX_TIMESTAMP()` consistently in SQL.

---

## 6. Appointments, Medical Records & Grooming Records

- **Strict Type Separation:** An appointment is **Medical OR Grooming**, never both. Customers create separate appointments if they want both.
- **Multi-Pet & Service Attachment:** One appointment can include multiple pets; services attach per pet within the appointment; the same service cannot be selected twice for the same pet in one appointment; at least one service is required to confirm.
- **Requested vs Performed Billing:** `requested_pet_service` captures initial requested price; `performed_service` records actual work performed and feeds `invoice_item` (service billing).
- **Scheduling & Facilities:** Prevent overlapping staff assignments (`AppointmentScheduleValidator`) and check facility availability (exam room, treatment room, surgical suite, lab) before assignment. Use `APPOINTMENT_STATUS` lookup values rather than ad-hoc strings.
- **Clinical & Grooming Records:** Medical records use SOAP (Subjective, Objective, Assessment, Plan) plus weight, temperature, and heart rate; finalized records are signed by a Veterinarian and **must never be silently overwritten** (corrections preserve an audit trail). Grooming records remain distinct from medical records.

---

## 7. Database, Audit, Security & Testing Safeguards

- **Schema & Migrations:** InnoDB + `utf8mb4` (match `utf8mb4_0900_ai_ci` on new tables linking `user_account`), `DECIMAL` for money/quantities (never `FLOAT`/`DOUBLE`). `database/migrate.php` splits on `;\n` and is **non-transactional** — write idempotent, carefully ordered SQL and matching `down/` rollback scripts.
- **Constraints vs Triggers:** Enforce simple invariants with SQL `UNIQUE`/`CHECK`/FK constraints and complex workflows in application transactions; avoid automatic triggers unless concurrency/cross-row integrity genuinely requires them.
- **Audit Logging (`App\Models\Audit`):** Use `Audit::record()` inside transactions so the audit row commits/rolls back with the mutation; use `Audit::log()` only for best-effort non-transactional logging. Never log secrets, OTPs, or password hashes.
- **Test Isolation:** Test suites under `tests/` may mutate the database configured in `.env`. Never run destructive tests against `vetgrpet_db` or production — use an isolated disposable MySQL instance/database and `MAIL_DRIVER=null`.
