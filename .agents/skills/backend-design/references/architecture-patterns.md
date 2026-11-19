# Backend Architecture Patterns

Proven backend architecture patterns — Clean Architecture, Hexagonal Architecture (Ports & Adapters), and Domain-Driven Design (DDD) — adapted for maintainable, testable, and cleanly bounded PHP 8.3 services.

**Given:** a service boundary, workflow, or module to architect or refactor.  
**Produces:** layered structure with inward dependency rules, explicit interfaces (ports), atomic transaction boundaries, and isolated test boundaries.

---

## When to Apply These Patterns

- Designing new application services or external integrations (payment gateways, mailers, dispatch workflows)
- Refactoring fat controllers where business logic, SQL, and HTTP formatting are entangled
- Separating bounded contexts across Pet Store, Veterinary Clinic, Grooming Services, and Identity/RBAC
- Eliminating dependency leaks where infrastructure or HTTP globals (`$_POST`, `$_SESSION`) bleed into domain rules
- Designing testable services where unit tests can run against in-memory or `null` adapters without live external services

---

## 1. Clean Architecture

**Layers (dependency flows strictly inward):**

- **Domain Rules & Invariants:** Core business rules, state transitions, value validation, and calculations (framework-free, no `$_POST`/`$_SESSION`/HTML).
- **Use Cases / Application Services (`app/Services/`):** Orchestrate domain transitions, transactions, and abstract ports.
- **Interface Adapters (`app/Controllers/`, `app/Models/`, concrete adapters):** Controllers translate HTTP requests/CSRF/session context into service calls and map outcomes to views/redirects; adapters implement storage/gateway/mail ports.
- **Frameworks & Drivers (`public/index.php`, PDO, PHPMailer, SMTP, HTTP):** Outermost boundary.

**Key Principles:**
- Dependencies point inward only; domain/service logic must not depend on view rendering or raw `$_SERVER`/`$_POST` superglobals.
- Cross boundaries via explicit PHP interfaces or narrow DTO/array contracts.
- Keep business rules testable independently of HTTP requests and external network calls.

---

## 2. Hexagonal Architecture (Ports & Adapters)

**Components:**
- **Application Core:** Orchestrates business rules (e.g., `OrderPaymentService`, `CustomerRegistrationPendingService`).
- **Ports:** Narrow PHP interfaces defining how the core talks to the outside world (e.g., `App\Services\Payment\OnlinePaymentGatewayInterface`).
- **Adapters:** Concrete implementations of ports (e.g., `UnconfiguredOnlinePaymentGateway`, hosted gateway adapter, `MailService` `smtp` vs `null` driver, PDO repositories).

**Benefits:**
- Swap providers or drivers (`null` mailer in tests vs `smtp` in production; unconfigured vs live payment gateway) without rewriting core workflows.
- Defer vendor/provider decisions to the outer edges while stabilizing core state machines and ledgers first.

---

## 3. Domain-Driven Design (DDD)

### Strategic Patterns
- **Bounded Contexts:** Keep distinct subdomains isolated rather than forcing one bloated model across everything:
  - **Identity & RBAC Context** (`user_account`, `customer`, `employee`, `account_role`, `role_permission`, `customer_registration_pending`)
  - **Pet Store Commerce & Inventory Context** (`business_product`, `inventory`, `inventory_batch`, `inventory_reservation`, `inventory_movement`, `sale`, `customer_order`)
  - **Payments & Remittance Ledger Context** (`payment`, `payment_attempt`, `refund_request`, `courier_cod_remittance`)
  - **Clinical & Grooming Context** (`appointment`, `requested_pet_service`, `performed_service`, `service_invoice`, `medical_record`, `grooming_record`)
- **Context Mapping & Anti-Corruption Layers (ACL):** When one context needs another (e.g., Customer Registration issuing an audit log, or Fulfillment checking Payment eligibility), translate through an explicit service/interface contract rather than reaching directly into another context's internal state transitions.
- **Ubiquitous Language:** Code identifiers, table columns, statuses, and UI labels must match domain terminology (`on_hand`, `committed`, `in_transit`, `available`, `performed_service`, `requested_pet_service`, `pending_invitation`).

### Tactical Patterns
- **Entities:** Objects/records with stable identity across state changes (`customer_order`, `appointment`, `user_account`).
- **Value Objects:** Immutable concepts defined by value with early invariant validation (e.g., normalized email, 6-digit OTP code, positive quantity, exact decimal money).
- **Aggregates (Consistency Boundaries):** Update an aggregate and its dependent rows atomically inside one DB transaction (e.g., `inventory` + `inventory_batch` + `inventory_reservation` + `inventory_movement`; `user_account` + `customer` + `audit_log`).
- **Repositories / Data Gateways:** Encapsulate SQL and row-locking (`FOR UPDATE`) cleanly rather than scattering duplicate queries across controllers.
- **Domain Events / Audit Trail:** Record immutable facts of what happened (`audit_log` via `Audit::record()`, `inventory_movement` rows) instead of mutating or deleting history.

---

## 4. Testing with In-Memory / Null Adapters

Use cases and services should accept port interfaces via constructor/method injection so behavior can be verified cleanly with fake/in-memory or `null` adapters before touching external networks or live databases:

```php
<?php
declare(strict_types=1);

interface MailDriverPort
{
    public function sendOtp(string $recipientEmail, string $otpCode): bool;
}

final class NullMailDriver implements MailDriverPort
{
    /** @var list<array{email:string,otp:string}> */
    public array $sent = [];

    public function sendOtp(string $recipientEmail, string $otpCode): bool
    {
        $this->sent[] = ['email' => $recipientEmail, 'otp' => $otpCode];
        return true;
    }
}

final class SendVerificationCodeService
{
    public function __construct(private readonly MailDriverPort $mailer) {}

    public function execute(string $email, string $otp): bool
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address.');
        }
        if (!preg_match('/^\d{6}$/', $otp)) {
            throw new InvalidArgumentException('OTP must preserve 6 numeric digits.');
        }
        return $this->mailer->sendOtp(strtolower(trim($email)), $otp);
    }
}
```

For SQL-heavy invariants (row locking, `UNIQUE` constraints, `GET_LOCK`, `UTC_TIMESTAMP()`), pair fast unit tests with isolated integration tests against a dedicated throwaway MySQL schema.

---

## 5. Architectural Troubleshooting

### Business logic accumulating in controllers
**Symptom:** Controller methods exceed HTTP parsing/auth checks and mix `$_POST` reading, stock math, SQL transactions, and `render()` calls.  
**Fix:** Extract orchestration into `app/Services/`. Keep controller methods focused on four steps: authorize/CSRF → parse/validate input → call service → render or redirect.

### Services reading HTTP superglobals directly
**Symptom:** `app/Services/` or `app/Models/` directly reads `$_POST`, `$_GET`, `$_SESSION`, or calls `exit` / `renderStatic()`.  
**Fix:** Pass explicit typed parameters (`int $actorUserId`, `int $unitId`, `string $clientIp`) from the controller into the service, and return structured results or throw domain exceptions.

### Infrastructure or provider details leaking into core state machines
**Symptom:** Order or refund state transitions depend on a specific payment vendor's status strings or HTTP payload shape.  
**Fix:** Define a provider-agnostic port (`OnlinePaymentGatewayInterface`) and translate vendor responses at the adapter edge.

### Value invariants validated too late
**Symptom:** Negative quantities, non-normalized emails, invalid status names, or floating-point rounding drift fail deep inside SQL queries.  
**Fix:** Validate and normalize inputs at the boundary (`filter_var`, `DECIMAL` exact arithmetic / integer minor units, explicit allowlists) before starting transactions.

### Cross-context bleed
**Symptom:** Walk-in POS (`sale`), online orders (`customer_order`), and service billing (`service_invoice`) share ad-hoc flags or mix return/refund rules.  
**Fix:** Enforce clear parent separation (a `payment` row belongs to exactly one of `invoice_id`, `sale_id`, or `order_id`), and keep separate domains (e.g., Customer Email OTP vs GCash/Maya `OtpService`) strictly isolated.
