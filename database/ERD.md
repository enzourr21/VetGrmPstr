# VETGRPET System --- Reconstructed ERD Reference

> **Document status:** Initial reconstructed reference, not final
> approval.\
> **Source basis:** Prior design decisions in the project conversation
> and the uploaded revised Mermaid diagram for
> product/inventory/sales/order/billing.\
> **Important:** This document is a structured reconstruction, not a
> replacement for the missing original diagrams. Attributes marked
> **PROPOSED** or **UNRESOLVED** must not be treated as approved schema
> requirements.

## 1. Design conventions

-   PK = primary key.
-   FK = foreign key.
-   UK = unique constraint.
-   Use InnoDB and `utf8mb4` for the MySQL implementation unless a
    reviewed requirement says otherwise.
-   Use appropriate exact numeric types for money and quantities; do not
    use floating-point for monetary values.
-   The product/inventory/sales/order/billing section below is
    transcribed from the uploaded revised ERD. The core and
    appointment/record sections are reconstructed from prior decisions
    and need review.

## 2. Core identity, business units, customers, pets, and RBAC

### 2.1 BUSINESS_UNIT

**Purpose:** One of the three operating units: Pet Store, Veterinary
Clinic, Grooming Services.

-   **PROPOSED attributes:** `business_unit_id` (PK),
    `business_unit_name`, `business_unit_status`.
-   **CONFIRMED relationship:** BUSINESS_UNIT has many branches and
    unit-scoped account-role assignments.
-   **CONFIRMED relationship:** BUSINESS_UNIT offers many
    BUSINESS_PRODUCT records and processes many sales/orders.
-   **UNRESOLVED:** Additional descriptive fields and exact status
    values.

### 2.2 BRANCH

**Purpose:** Physical operating location belonging to a business unit.

-   **PROPOSED attributes:** `branch_id` (PK), `business_unit_id` (FK),
    `branch_name`, address/contact fields, status.
-   **CONFIRMED:** Multiple branches are supported.
-   **CONFIRMED (uploaded ERD):** A branch may be associated with many
    SALE and CUSTOMER_ORDER records.
-   **UNRESOLVED:** Whether a branch belongs to exactly one business
    unit and whether shared premises across units are modeled as
    separate branches.

### 2.3 USER_ACCOUNT

**Purpose:** Login identity.

-   **PROPOSED attributes:** `user_account_id` (PK),
    username/email/login identifier, password hash, account status,
    created/updated timestamps.
-   **CONFIRMED:** May link to CUSTOMER and/or EMPLOYEE.
-   **UNRESOLVED:** Exact unique login identifier, recovery fields, and
    whether one account can link to both a customer and employee record.

### 2.4 CUSTOMER

**Purpose:** Customer identity/profile.

-   **PROPOSED attributes:** `customer_id` (PK), `user_account_id` (FK,
    nullable if guest-only customer records are supported), name/contact
    fields, status.
-   **CONFIRMED:** One customer may own many pets.
-   **CONFIRMED:** CUSTOMER_ORDER requires customer_id; SALE.customer_id
    is optional for walk-in purchases.
-   **UNRESOLVED:** Exact customer profile fields and whether a customer
    record is required for every non-guest sale.

### 2.5 EMPLOYEE

**Purpose:** Staff identity and employment details.

-   **PROPOSED attributes:** `employee_id` (PK), `user_account_id` (FK),
    `position_id` (FK or constrained position value), employee
    details/status.
-   **CONFIRMED:** Employees process sales and appointments/services.
-   **CONFIRMED:** Each employee has one default position; authorization
    is governed separately by roles/permissions.
-   **UNRESOLVED:** Whether an employee may work at multiple branches
    and how branch assignments are represented.

### 2.6 PET

**Purpose:** Registered animal profile.

-   **PROPOSED attributes:** `pet_id` (PK), `customer_id` (FK), pet
    name/species/breed/sex/date-of-birth or age fields, identifying
    notes/status.
-   **CONFIRMED:** Each registered pet has one registered owner; one
    customer may own many pets.
-   **UNRESOLVED:** Exact pet attributes, ownership transfer, and
    whether species/breed are lookup tables.

### 2.7 RBAC entities

-   **ROLE** --- role definition; proposed `role_id` (PK), `role_name`,
    description.
-   **PERMISSION** --- individual capability; proposed `permission_id`
    (PK), `permission_code`, description.
-   **ACCOUNT_ROLE** --- proposed junction with `user_account_id` (FK),
    `role_id` (FK), `business_unit_id` (FK); role assignment is
    business-unit scoped.
-   **ROLE_PERMISSION** --- proposed junction with `role_id` (FK),
    `permission_id` (FK).
-   **CONFIRMED:** System administrator is scoped to an assigned
    business unit; no automatic global authority.
-   **UNRESOLVED:** Whether role definitions are global reusable
    definitions or duplicated per unit, and exact uniqueness
    constraints.

## 3. Product, inventory, sales, orders, and billing

> The following entities and attributes are transcribed from the
> uploaded revised ERD Markdown. Business rules and known unresolved
> conflicts are documented in `BUSINESS_RULES.md`.

### 3.1 PRODUCT

-   `product_id` (PK)
-   `product_name`
-   `description`
-   `product_category`

### 3.2 BUSINESS_PRODUCT

-   `business_product_id` (PK)
-   `business_unit_id` (FK, UK component)
-   `product_id` (FK, UK component)
-   `selling_price`
-   `product_status`
-   `requires_batch_tracking`
-   Unique constraint: `(business_unit_id, product_id)`.

### 3.3 SALE

-   `sale_id` (PK)
-   `business_unit_id` (FK)
-   `branch_id` (FK)
-   `customer_id` (FK, nullable for walk-in)
-   `employee_id` (FK)
-   `sale_date`
-   `total_amount`

### 3.4 CUSTOMER_ORDER

-   `order_id` (PK)
-   `customer_id` (FK)
-   `business_unit_id` (FK)
-   `branch_id` (FK)
-   `order_date`
-   `fulfillment_method`
-   `order_status`
-   `total_amount`

### 3.5 SALE_ITEM

-   `sale_item_id` (PK)
-   `sale_id` (FK)
-   `business_product_id` (FK)
-   `quantity`
-   `unit_price`

### 3.6 ORDER_ITEM

-   `order_item_id` (PK)
-   `order_id` (FK)
-   `business_product_id` (FK)
-   `quantity`
-   `unit_price`

### 3.7 INVENTORY

-   `inventory_id` (PK)
-   `business_product_id` (FK, UK)
-   `on_hand`
-   `committed`
-   Unique constraint on `business_product_id` (one inventory record per
    business product).

### 3.8 INVENTORY_BATCH

-   `batch_id` (PK)
-   `inventory_id` (FK)
-   `batch_number`
-   `expiration_date`
-   `quantity`

### 3.9 INVENTORY_MOVEMENT

-   `movement_id` (PK)
-   `inventory_id` (FK)
-   `batch_id` (FK, nullable where batch tracking is not used)
-   `sale_item_id` (FK, nullable)
-   `reservation_id` (FK, nullable)
-   `movement_type`
-   `quantity`
-   `movement_date`

### 3.10 INVENTORY_RESERVATION

-   `reservation_id` (PK)
-   `order_item_id` (FK)
-   `inventory_id` (FK)
-   `batch_id` (FK, nullable where batch tracking is not used)
-   `reserved_quantity`
-   `reservation_status`

### 3.11 SERVICE_INVOICE

-   `invoice_id` (PK)
-   `appointment_id` (FK, UK)
-   `invoice_date`
-   `total_amount`
-   `invoice_status`
-   Unique constraint on `appointment_id`.

### 3.12 INVOICE_ITEM

-   `invoice_item_id` (PK)
-   `invoice_id` (FK)
-   `performed_service_id` (FK)
-   `unit_price`
-   `quantity`

### 3.13 PAYMENT

-   `payment_id` (PK)
-   `invoice_id` (FK, nullable)
-   `sale_id` (FK, nullable)
-   `order_id` (FK, nullable)
-   `original_payment_id` (self-referencing FK, nullable)
-   `payment_type`
-   `amount`
-   `payment_date`
-   `payment_method`
-   `payment_status`
-   **CONFIRMED (uploaded ERD rule):** Exactly one of `invoice_id`,
    `sale_id`, or `order_id` must be non-NULL for each payment/refund
    row.
-   **CONFIRMED (uploaded ERD rule):** Refund rows reference an original
    payment and preserve the original payment row.
-   **UNRESOLVED:** The uploaded ERD's stock return movement rules
    conflict; see `BUSINESS_RULES.md`.

## 4. Appointments and service records (reconstructed)

### 4.1 APPOINTMENT

-   **PROPOSED attributes:** `appointment_id` (PK), `business_unit_id`
    (FK), `branch_id` (FK), `customer_id` (FK), `appointment_status_id`
    (FK), appointment category/type, `appointment_begin`,
    `appointment_end`, request/creation timestamps and notes.
-   **CONFIRMED:** Each appointment is either Medical or Grooming, never
    both.
-   **CONFIRMED:** A customer who wants both must create separate
    appointments.
-   **CONFIRMED:** One appointment may include multiple pets.
-   **UNRESOLVED:** Exact fields, status transition rules, and
    employee/facility assignment timing.

### 4.2 APPOINTMENT_STATUS

-   **PROPOSED attributes:** `appointment_status_id` (PK),
    `status_name`, description.
-   **CONFIRMED:** A separate status lookup table is preferred over an
    enum.
-   **PROPOSED status values:** Pending, Awaiting Customer Response,
    Confirmed, Rejected, Cancelled, In Progress, Completed, No-Show.
-   **UNRESOLVED:** Final status list and allowed transitions.

### 4.3 APPOINTMENT_PET

-   **PROPOSED purpose:** Junction linking an appointment to one or more
    pets.
-   **PROPOSED attributes:** `appointment_pet_id` (PK), `appointment_id`
    (FK), `pet_id` (FK).
-   **CONFIRMED:** Service requests must be associated with the specific
    pet within the appointment.
-   **UNRESOLVED:** Whether to use a surrogate key or composite primary
    key; exact uniqueness rule for `(appointment_id, pet_id)`.

### 4.4 SERVICE

-   **PROPOSED attributes:** `service_id` (PK), `service_name`,
    `service_category` (Medical/Grooming), description, base price,
    `estimated_duration_minutes`, status.
-   **CONFIRMED:** Service category is constrained to Medical or
    Grooming; no separate category table is currently planned.
-   **CONFIRMED:** Services have predefined estimated duration and
    price.
-   **UNRESOLVED:** Exact fields and whether pricing/duration is
    versioned or copied to the request/performed record.

### 4.5 REQUESTED_PET_SERVICE

-   **PROPOSED attributes:** `requested_pet_service_id` (PK),
    `appointment_pet_id` (FK), `service_id` (FK), requested price,
    request timestamp/status.
-   **CONFIRMED:** Represents initially requested services and stores
    historical requested price.
-   **CONFIRMED:** Duplicate selection of the same service for the same
    pet within one appointment is prohibited.
-   **UNRESOLVED:** Exact request status and whether a customer may
    request a service later through an amendment.

### 4.6 PERFORMED_SERVICE

-   **PROPOSED attributes:** `performed_service_id` (PK),
    `appointment_pet_id` or `requested_pet_service_id` (FK; relationship
    not finalized), `service_id` (FK), performed price, quantity,
    performed status, performer/confirming employee references, consent
    metadata where additional service was authorized.
-   **CONFIRMED:** Represents work actually performed and is the billing
    source of truth.
-   **CONFIRMED:** A veterinarian may add a clinically necessary service
    after obtaining customer consent.
-   **CONFIRMED:** Nurse may confirm already requested/authorized
    services but cannot independently add billable services.
-   **UNRESOLVED:** Whether performed services must always originate
    from requested services or can be created independently for
    additional consented services; exact FK design and consent fields.

### 4.7 APPOINTMENT_STAFF_SERVICE

-   **PROPOSED:** Assignment junction for employees assigned to an
    appointment and/or a specific service.
-   **CONFIRMED:** Same employee cannot be assigned to overlapping
    appointments.
-   **UNRESOLVED:** Final key structure, assignment scope, role/position
    snapshot, qualification checks, and whether assignment is mandatory
    before confirmation.

### 4.8 Facilities

-   **PROPOSED entities:** FACILITY and APPOINTMENT_FACILITY.
-   **CONFIRMED facility types discussed:** Examination room, treatment
    room, surgical suite, laboratory.
-   **CONFIRMED:** Availability must be checked before assignment.
-   **UNRESOLVED:** Exact attributes, time-slot model, branch
    relationship, and whether multiple facilities can be assigned to one
    appointment.

## 5. Clinical and grooming records (reconstructed)

### 5.1 MEDICAL_RECORD

-   **PROPOSED attributes:** `medical_record_id` (PK), appointment/pet
    reference, writer employee reference, SOAP fields (Subjective,
    Objective, Assessment, Plan), weight, temperature, heart rate,
    diagnosis/treatment/procedure notes, record status, finalized/signed
    metadata.
-   **CONFIRMED:** Clinical record is for a pet's veterinary appointment
    and includes SOAP and physical measurements.
-   **CONFIRMED:** A veterinarian finalizes/signs the record.
-   **CONFIRMED:** Finalized records are not silently overwritten;
    corrections preserve audit history.
-   **UNRESOLVED:** Exact relationship cardinality with appointment and
    appointment-pet; whether diagnoses, prescriptions, procedures, and
    diagnostics are separate normalized entities.

### 5.2 PRESCRIPTION / MEDICATION / DIAGNOSTICS

-   **PROPOSED:** Separate entities may be needed for prescriptions,
    prescribed items, administered medication, diagnostic requests, and
    diagnostic results.
-   **CONFIRMED:** Veterinary records may include prescriptions,
    medications, diagnostics/results, treatments, procedures,
    nursing/recovery notes, and discharge instructions.
-   **UNRESOLVED:** Exact entity boundaries and attributes. Do not
    implement as final schema without further review.

### 5.3 GROOMING_RECORD

-   **PROPOSED attributes:** `grooming_record_id` (PK), appointment-pet
    or performed-service reference, employee reference, grooming notes,
    condition/coat observations, completion time, sign-off fields.
-   **CONFIRMED:** Grooming records are distinct from medical records
    and document grooming work.
-   **UNRESOLVED:** Exact fields and cardinality.

## 6. Relationship summary

The following relationships are established or intended; where marked
proposed, confirm before schema implementation.

-   BUSINESS_UNIT 1-to-many BRANCH.
-   BUSINESS_UNIT 1-to-many BUSINESS_PRODUCT.
-   PRODUCT 1-to-many BUSINESS_PRODUCT.
-   BUSINESS_UNIT 1-to-many SALE and CUSTOMER_ORDER.
-   BRANCH 1-to-many SALE and CUSTOMER_ORDER.
-   CUSTOMER 1-to-many PET.
-   CUSTOMER 1-to-many CUSTOMER_ORDER.
-   CUSTOMER 0-to-many SALE (SALE may be walk-in with no customer).
-   SALE 1-to-many SALE_ITEM.
-   CUSTOMER_ORDER 1-to-many ORDER_ITEM.
-   BUSINESS_PRODUCT 1-to-1 INVENTORY.
-   INVENTORY 1-to-many INVENTORY_BATCH, INVENTORY_MOVEMENT,
    INVENTORY_RESERVATION.
-   ORDER_ITEM 1-to-many INVENTORY_RESERVATION.
-   APPOINTMENT 1-to-many APPOINTMENT_PET (proposed junction).
-   APPOINTMENT_PET 1-to-many REQUESTED_PET_SERVICE and
    PERFORMED_SERVICE (exact performed-service link unresolved).
-   APPOINTMENT 0-to-1 SERVICE_INVOICE (unique appointment_id).
-   SERVICE_INVOICE 1-to-many INVOICE_ITEM.
-   PERFORMED_SERVICE 1-to-many INVOICE_ITEM as drawn in the uploaded
    ERD (confirm whether a performed service may be split across invoice
    lines).
-   PAYMENT belongs to exactly one of SERVICE_INVOICE, SALE,
    CUSTOMER_ORDER.
-   PAYMENT may have multiple refund child PAYMENT rows.
-   USER_ACCOUNT may link to CUSTOMER and/or EMPLOYEE.
-   USER_ACCOUNT many-to-many ROLE through ACCOUNT_ROLE, scoped by
    BUSINESS_UNIT.
-   ROLE many-to-many PERMISSION through ROLE_PERMISSION.
-   Appointment/staff/facility relationships remain provisional pending
    confirmation.

## 7. Approval checklist before schema.sql

Do not generate the final SQL schema until the following are reviewed:

1.  Confirm exact core entity attributes and customer/account linkage.
2.  Confirm whether employees can belong to multiple branches.
3.  Confirm inventory branch scope (per business unit or per branch).
4.  Resolve the uploaded ERD's `sale_refund` versus
    `return_sellable`/`return_damaged` movement-type conflict.
5.  Confirm appointment-pet and requested/performed service junction
    structure.
6.  Confirm staff assignment scope and when assignment is required.
7.  Confirm facility assignment/cardinality.
8.  Confirm medical-record cardinality and prescription/diagnostic
    entity boundaries.
9.  Confirm grooming-record fields and cardinality.
10. Confirm which of these features are included in the first MVP
    schema.
