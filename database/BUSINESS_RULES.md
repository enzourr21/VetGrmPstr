# VETGRPET System --- Business Rules (Initial Reconstruction)

> **Document status:** Initial reconstruction for review.\
> **Important:** This document combines decisions previously discussed
> with the user and details visible in the uploaded
> product/inventory/sales/order/billing ERD. It is not yet an approved
> final specification. Items are labeled **CONFIRMED**, **PROPOSED**, or
> **UNRESOLVED**. Do not implement unresolved rules as if they were
> approved.

## 1. System scope and business units

### 1.1 Business units

-   **CONFIRMED:** The system supports three business units: Pet Store,
    Veterinary Clinic, and Grooming Services.
-   **CONFIRMED:** Shared identity information may be used across the
    three units, while unit-specific operations, transactions,
    inventory, and records remain appropriately separated.
-   **CONFIRMED:** A business unit may operate through multiple
    branches.
-   **UNRESOLVED:** Whether every business unit must have at least one
    branch before it can operate.

### 1.2 Shared people and accounts

-   **CONFIRMED:** Customer and employee identities are separate
    business concepts. A user account may be linked to a customer
    record, an employee record, or both, as appropriate.
-   **CONFIRMED:** Walk-in product purchases may occur without
    registering a customer.
-   **CONFIRMED:** A registered customer can own multiple pets; each
    registered pet has one registered owner.
-   **CONFIRMED:** A shared account and role/permission model is
    intended.
-   **CONFIRMED:** Roles are assigned within a business-unit context. A
    role assignment in one unit does not automatically grant access to
    another unit.
-   **CONFIRMED:** Position describes a person's job; Role is a
    permission bundle; Permission is an individual capability.
-   **UNRESOLVED:** Exact account lifecycle rules, such as whether an
    employee account can be linked to multiple employee records or
    whether one account can hold multiple roles in the same unit.

## 2. Access control and staff responsibilities

### 2.1 Access-control structure

-   **CONFIRMED:** The intended RBAC structure includes USER_ACCOUNT,
    ROLE, PERMISSION, ACCOUNT_ROLE, and ROLE_PERMISSION.
-   **CONFIRMED:** ACCOUNT_ROLE is scoped by business_unit_id.
-   **CONFIRMED:** Even the SYSTEM_ADMINISTRATOR role is scoped to an
    assigned business unit; it does not automatically grant global
    access.
-   **UNRESOLVED:** Whether permissions are also explicitly scoped by
    business unit, branch, or record ownership beyond the role
    assignment.

### 2.2 Veterinary roles

-   **CONFIRMED:** Veterinarian: consultation, assessment, diagnosis,
    treatment planning, prescriptions, medical records, and
    finalization/signing.
-   **CONFIRMED:** Nurse: may administer authorized prescribed
    treatments/medications and document nursing work; may confirm an
    already requested or authorized service was performed, but cannot
    independently add billable services.
-   **CONFIRMED:** Technician: performs technical tasks and diagnostic
    processing; must not independently interpret results or make
    treatment decisions outside the technician's scope.
-   **CONFIRMED:** Receptionist: handles customer-facing administration
    and appointment coordination; does not receive unrestricted
    clinical-record access.
-   **CONFIRMED:** Business Manager: operational management within the
    assigned unit; read-only access to medical records; may configure
    estimated service durations when authorized; does not edit employee
    profiles or performance evaluations.
-   **CONFIRMED:** System Administrator: manages accounts, roles, and
    permissions within the assigned unit; this role does not
    automatically confer clinical or operational authority.
-   **UNRESOLVED:** Whether technicians may confirm performed services,
    and the precise limits of their authority to add or amend service
    records.

## 3. Customer and pet management

-   **CONFIRMED:** One customer may own many pets.
-   **CONFIRMED:** Each registered pet has one registered owner.
-   **CONFIRMED:** A walk-in product sale may have no customer_id.
-   **CONFIRMED:** Customer-facing features are intended to include
    profile management, pet management, business selection, product
    browsing, cart/checkout, orders, appointment requests, pet-care
    history, and notifications.
-   **CONFIRMED:** Reviews are excluded from the current scope.
-   **CONFIRMED:** Customers may access only their own profile, pets,
    cart, orders, appointments, and permitted records.
-   **UNRESOLVED:** Whether ownership transfer, co-owners, temporary
    caretakers, or deceased/inactive pets need to be supported.

## 4. Product catalog and business-specific products

-   **CONFIRMED:** PRODUCT stores global catalog information.
-   **CONFIRMED:** BUSINESS_PRODUCT connects a product to a business
    unit and stores business-specific selling information, including
    selling price and product status.
-   **CONFIRMED:** A product may be listed by one business unit and not
    another.
-   **CONFIRMED:** Product status is distinct from stock quantity. An
    active product can be out of stock.
-   **CONFIRMED:** The uploaded ERD specifies UNIQUE(business_unit_id,
    product_id) for BUSINESS_PRODUCT.
-   **CONFIRMED:** Veterinary products in scope are animal-health
    products (such as veterinary medicines, injectables, vaccines, and
    clinic animal-health products), not general human pharmaceuticals.
-   **UNRESOLVED:** Exact product-category taxonomy and whether product
    category should be a lookup table or constrained value.
-   **UNRESOLVED:** Whether non-veterinary products require batch/expiry
    tracking or only veterinary products do.

## 5. Inventory

### 5.1 Inventory quantities and source of truth

-   **CONFIRMED:** INVENTORY is linked one-to-one with BUSINESS_PRODUCT
    in the uploaded ERD; UNIQUE(business_product_id) enforces this.
-   **CONFIRMED:** On Hand means physical stock.
-   **CONFIRMED:** Committed means stock reserved for confirmed orders.
-   **CONFIRMED:** Available is derived: Available = On Hand -
    Committed; it should not be stored as an independent quantity.
-   **CONFIRMED:** In Transit represents dispatched stock not yet
    confirmed delivered.
-   **CONFIRMED:** Damaged/Lost stock is not sellable.
-   **CONFIRMED:** A cart does not reserve inventory.
-   **CONFIRMED:** Order confirmation increases Committed, not On Hand.
-   **CONFIRMED:** Successful pickup reduces On Hand and releases the
    corresponding Committed quantity.
-   **CONFIRMED:** Delivery dispatch reduces On Hand, releases
    Committed, and increases In Transit.
-   **CONFIRMED:** Successful delivery does not deduct stock again.
-   **CONFIRMED:** Failed deliveries and returns require inspection;
    returned products must not automatically become sellable.

### 5.2 Batch and expiry tracking

-   **CONFIRMED:** Veterinary inventory requires batch/lot and expiry
    tracking.
-   **CONFIRMED:** The uploaded ERD uses
    BUSINESS_PRODUCT.requires_batch_tracking to distinguish
    batch-tracked products.
-   **CONFIRMED (uploaded ERD):** For non-batch-tracked products,
    INVENTORY.on_hand is the physical quantity and no INVENTORY_BATCH
    rows exist.
-   **CONFIRMED (uploaded ERD):** For batch-tracked products, physical
    quantity is held by INVENTORY_BATCH.quantity, and INVENTORY.on_hand
    is the aggregate of batch quantities.
-   **CONFIRMED (uploaded ERD):** INVENTORY.committed represents the sum
    of open reservation quantities for that inventory.
-   **CONFIRMED (uploaded ERD):** Every stock change is represented by
    an INVENTORY_MOVEMENT and applied atomically with the corresponding
    quantity updates.
-   **CONFIRMED (uploaded ERD):** FEFO allocation is intended for
    batch-tracked products: earliest-expiring eligible batch first,
    splitting across batches when needed.
-   **UNRESOLVED:** Whether to support batches without expiration dates,
    recalled batches, quarantined stock, and stock transfers between
    branches in the MVP.
-   **UNRESOLVED:** Whether inventory is branch-specific. The uploaded
    diagram links INVENTORY to BUSINESS_PRODUCT but does not show
    branch_id on INVENTORY. Do not assume per-branch stock until
    decided.

### 5.3 Inventory movement and reservations

-   **CONFIRMED (uploaded ERD):** INVENTORY_MOVEMENT records quantity
    changes and may reference a batch, sale item, and/or reservation.
-   **CONFIRMED (uploaded ERD):** sale_item_id and reservation_id are
    mutually exclusive; both may be NULL for
    adjustments/restocks/write-offs.
-   **CONFIRMED (uploaded ERD):** Outbound movement quantities are
    negative; inbound quantities are positive.
-   **CONFIRMED (uploaded ERD):** A completed walk-in sale creates a
    negative 'sale' movement per SALE_ITEM.
-   **CONFIRMED (uploaded ERD):** A voided sale creates a positive
    'sale_void' reversal movement without editing/deleting the original
    movement.
-   **CONFIRMED (uploaded ERD):** An order reservation retains its
    original reserved_quantity; fulfillment progress is derived from
    outbound 'order_fulfillment' movement quantities.
-   **CONFIRMED (uploaded ERD):** Partial fulfillment is supported;
    cancellation releases only the unfulfilled committed quantity.
-   **CONFIRMED (uploaded ERD):** Reservation, movement, and inventory
    quantity/status changes must be atomic within a database
    transaction.
-   **UNRESOLVED:** The uploaded ERD's Rule 6 and Rule 8 still conflict
    about 'sale_refund' versus 'return_sellable'/'return_damaged'. The
    last reviewed version was marked **NOT APPROVED** for this reason.
    Do not implement the refund/return movement rules until corrected
    and approved.

## 6. Product sales and customer orders

### 6.1 Walk-in sales

-   **CONFIRMED:** SALE represents a product sale processed by a
    business unit and branch.
-   **CONFIRMED:** SALE.customer_id is optional to allow walk-in
    purchases.
-   **CONFIRMED:** SALE.employee_id identifies the employee processing
    the sale.
-   **CONFIRMED:** SALE_ITEM records the business product, quantity, and
    unit price at the time of sale.
-   **CONFIRMED:** Product sales are separate from service invoices.
-   **UNRESOLVED:** Exact SALE status, void timing, cashier permissions,
    discounts, taxes, and receipt-number rules.

### 6.2 Customer orders

-   **CONFIRMED:** CUSTOMER_ORDER represents a customer order and
    requires customer_id.
-   **CONFIRMED:** CUSTOMER_ORDER is associated with a business unit and
    fulfilling branch.
-   **CONFIRMED:** ORDER_ITEM records the selected BUSINESS_PRODUCT,
    quantity, and unit price.
-   **CONFIRMED:** Separate checkout/transactions are used for each
    business unit.
-   **CONFIRMED:** Pickup or delivery is selected at checkout where
    supported.
-   **CONFIRMED:** Inventory is not reserved while items are merely in
    the cart.
-   **CONFIRMED:** Confirmation commits/reserves stock; fulfillment
    movements reduce physical stock according to the pickup/delivery
    workflow.
-   **UNRESOLVED:** Exact order status lifecycle,
    payment-before-confirmation policy, delivery provider data,
    failed-delivery retry rules, and cancellation eligibility.

## 7. Payments, service invoices, and returns

### 7.1 Service invoices

-   **CONFIRMED:** SERVICE_INVOICE is associated with an APPOINTMENT.
-   **CONFIRMED:** The uploaded ERD specifies UNIQUE(appointment_id),
    meaning at most one service invoice per appointment.
-   **CONFIRMED:** INVOICE_ITEM references PERFORMED_SERVICE, not the
    initially requested service record.
-   **CONFIRMED:** Service invoices are separate from product
    SALE/CUSTOMER_ORDER transactions.
-   **UNRESOLVED:** Whether one appointment can be billed in
    installments or whether multiple invoices are needed for later
    adjustments.

### 7.2 Payment parent and refunds

-   **CONFIRMED (uploaded ERD):** PAYMENT is intended to belong to
    exactly one parent transaction: SERVICE_INVOICE, SALE, or
    CUSTOMER_ORDER. Exactly one of invoice_id, sale_id, or order_id must
    be non-NULL.
-   **CONFIRMED (uploaded ERD):** A refund is a new PAYMENT row with
    payment_type='refund' and original_payment_id referencing the
    original payment. The original payment is preserved.
-   **CONFIRMED (uploaded ERD):** Partial refunds and multiple refunds
    against one original payment are intended.
-   **CONFIRMED (uploaded ERD):** Total refunds must not exceed the
    original payment amount.
-   **UNRESOLVED:** The uploaded ERD's payment/refund design needs final
    validation alongside the physical-return movement correction. The
    previous review approved most payment rules but did not approve the
    complete subsystem due to the movement-type conflict.

### 7.3 Physical returns

-   **UNRESOLVED:** Distinguish monetary refunds from physical returns.
    The latest uploaded rules describe return_sellable and
    return_damaged separately from PAYMENT refunds, but Rule 6 still
    uses sale_refund. This conflict must be corrected before
    implementation.
-   **CONFIRMED:** A returned product must be inspected before being
    made sellable.
-   **CONFIRMED:** A monetary refund may occur without physical stock
    return, and a physical return may occur without a monetary refund;
    these are separate events.

## 8. Appointments and services

-   **CONFIRMED:** An appointment is either Medical or Grooming, never
    both.
-   **CONFIRMED:** If a customer wants both medical and grooming
    services, they create separate appointments.
-   **CONFIRMED:** One appointment may include multiple pets.
-   **CONFIRMED:** Services are associated with the specific pet within
    the appointment.
-   **CONFIRMED:** Service category is constrained to Medical or
    Grooming; a separate category table is not currently desired.
-   **CONFIRMED:** A medical appointment interface shows medical
    services; a grooming appointment interface shows grooming services.
-   **CONFIRMED:** An appointment must have at least one selected
    service before it can be confirmed.
-   **CONFIRMED:** The same service cannot be selected more than once
    for the same pet within the same appointment.
-   **CONFIRMED:** REQUESTED_PET_SERVICE represents services initially
    requested by the customer and stores the historical requested price.
-   **CONFIRMED:** PERFORMED_SERVICE represents services actually
    performed and is the source for service billing.
-   **CONFIRMED:** A veterinarian may add a clinically necessary service
    after obtaining customer consent; consent decision, actor, and time
    should be recorded.
-   **CONFIRMED:** A nurse may confirm an already requested/authorized
    service was performed but cannot add a billable service.
-   **UNRESOLVED:** Exact appointment confirmation timing relative to
    employee/facility assignment.
-   **UNRESOLVED:** Exact authority of technicians to confirm or add
    service records.
-   **UNRESOLVED:** Final APPOINTMENT_STAFF_SERVICE structure and
    whether assignment is at appointment level, service level, or both.
-   **UNRESOLVED:** Exact cancellation, rescheduling, no-show, and
    customer-response workflow.
-   **UNRESOLVED:** Final grooming extra-duration and
    additional-employee fee rules.

### 8.1 Appointment statuses

-   **CONFIRMED:** A separate APPOINTMENT_STATUS lookup table is
    preferred over a hard-coded enum.
-   **PROPOSED:** Status values discussed include Pending, Awaiting
    Customer Response, Confirmed, Rejected, Cancelled, In Progress,
    Completed, and No-Show.
-   **UNRESOLVED:** Final status transitions and which roles may perform
    each transition.

### 8.2 Scheduling and facilities

-   **CONFIRMED:** The system must prevent the same employee from being
    assigned to overlapping appointments.
-   **CONFIRMED:** Multiple branches are supported.
-   **CONFIRMED:** Facilities include examination room, treatment room,
    surgical suite, and laboratory.
-   **CONFIRMED:** Facility availability should be checked before
    assignment.
-   **UNRESOLVED:** Whether facility assignment is mandatory at
    confirmation or can occur later.
-   **UNRESOLVED:** Exact facility capacity and whether one appointment
    may reserve multiple facilities over different time intervals.
-   **UNRESOLVED:** Whether appointment duration is calculated from
    selected service estimated durations, staff, and facility
    constraints or entered/adjusted by staff.

## 9. Veterinary and grooming records

### 9.1 Medical records

-   **CONFIRMED:** Medical records are clinical records for a pet's
    veterinary appointment, not merely a list of appointments.
-   **CONFIRMED:** The intended clinical structure includes SOAP:
    Subjective, Objective, Assessment, and Plan.
-   **CONFIRMED:** Physical state measurements include weight,
    temperature, and heart rate. Repeated measurement series are not
    required for the current scope.
-   **CONFIRMED:** Clinical content may include diagnosis, treatment,
    procedures, medication, prescriptions, diagnostics/results,
    nursing/recovery notes, and discharge instructions.
-   **CONFIRMED:** The record writer is associated with the appointment.
-   **CONFIRMED:** A veterinarian finalizes and signs the medical
    record.
-   **CONFIRMED:** Finalized records must not be silently overwritten;
    corrections preserve an audit trail.
-   **PROPOSED:** Medical-record statuses: IN_PROGRESS,
    AWAITING_TEST_RESULTS, FINALIZED, REVISED.
-   **UNRESOLVED:** Whether a medical record is strictly one-to-one with
    an appointment or whether multiple clinical encounters/records may
    be created for one appointment.

### 9.2 Grooming records

-   **CONFIRMED:** Grooming records are distinct from medical records
    and document grooming work.
-   **UNRESOLVED:** Exact grooming-record fields, sign-off, revision
    history, and whether a grooming record is one-to-one with an
    appointment-pet or with each performed grooming service.

## 10. Branches and facilities

-   **CONFIRMED:** The system supports multiple branches.
-   **CONFIRMED:** SALE and CUSTOMER_ORDER are associated with a branch
    in the uploaded ERD.
-   **CONFIRMED:** Appointment facilities must be checked for
    availability before assignment.
-   **UNRESOLVED:** Whether customers, employees, services, products,
    inventory, and appointments are always branch-scoped or may be
    shared at business-unit level.
-   **UNRESOLVED:** Whether inventory quantities are tracked per branch
    or only per business unit.

## 11. Implementation safeguards

-   **CONFIRMED:** Database constraints should enforce rules where
    practical; application logic should handle workflows and validations
    that cannot be expressed safely as simple constraints.
-   **CONFIRMED:** Do not add triggers automatically when application
    transactions can enforce the rule clearly and safely. Consider
    triggers only where needed for cross-row or concurrency-sensitive
    enforcement.
-   **CONFIRMED:** Preserve audit history for stock movements,
    payments/refunds, and finalized clinical records.
-   **CONFIRMED:** Do not silently overwrite or delete historical
    transactions or finalized records.
-   **PROPOSED:** Build an MVP schema from the confirmed, bounded core
    first; defer unresolved advanced workflows rather than guessing.
