# PawCariño — Payment Gateway Architecture Specification (Business-Rules Reconciliation)

**Document Type:** Consolidated Payment Gateway & Order-Inventory Architecture Specification  
**Location:** `d:\VETGRPETproj\PAYMENT_GATEWAY_ARCHITECTURE_SPEC.md`  
**Status:** Revised Proposed Architecture — **Not Implemented / Not Frozen / Provider Not Yet Selected**    
**Baseline Documentation Authority:** `database/BUSINESS_RULES.md`, `database/ERD.md`, `database/migrations/001`–`019`, confirmed owner decisions (**OD-09**, **OD-10**, **OD-11**), and repository source inspections.  
**Reconciliation note:** Where OD-09 conflicts with the older dispatch wording in `BUSINESS_RULES.md`, OD-09 is shown as the later owner clarification, but the source document/ERD amendment is a Gate 0 prerequisite. The Rule 6/8 movement conflict remains unresolved and is not decided here.

> ### Epistemic & Authority Legend
> Every architectural rule in this specification is tagged by source authority:
> - **`[Verified in Source]`** — Behavior directly observed in current PHP controllers/models/views or SQL migrations.
> - **`[Documented Rule]`** — Policy confirmed in `database/BUSINESS_RULES.md` or `database/ERD.md`.
> - **`[Confirmed Owner Clarification]`** — Confirmed owner policy decisions (**OD-09**, **OD-10**, **OD-11**).
> - **`[Proposed Technical Design]`** — Provider-independent software/database engineering recommendation (not an approved owner decision).
> - **`[Recommendation — Awaiting Approval]`** — Unresolved business policy recommendation where `BUSINESS_RULES.md` is unresolved or silent.
> - **`[Provider-Dependent — Unverified]`** — Commercial, API, webhook, or timeout detail requiring verification during Provider Selection and Sandbox Evaluation.

---

## 1. Scope and Explicit Exclusions

### 1.1 In-Scope (Proposed Phase 1)
* **Business Unit Transaction Separation `[Documented Rule]`:** Online checkouts and transactions are separated per business unit (`BUSINESS_RULES.md#L17-L19, #L224-L225`), and customer-facing product browsing across business units is supported (`#L90-L92, #L106-L107`).
* **Phase 1 Fulfillment & Ordering Scope `[Recommendation — Awaiting Approval]`:** Restrict online order checkout submission (`/cart/{bu}/add`, `/checkout/{bu}`) and fulfillment (`PetStoreReceptionistController`, `DeliveryController`, `ManagerController`, walk-in POS stock-concurrency compatibility) to Pet Store (`business_unit_id = 1`) while keeping Units `2` (Veterinary Clinic) and `3` (Grooming Services) catalog-browsable (`/shop`, `/shop/{bu}`) until their online product fulfillment workflows are commissioned (`ISS-15`).
* **Payment Channels:**
  * Provider-agnostic hosted online payment gateway interface supporting digital payments (cards, e-wallets, QR) `[Proposed Technical Design]` *(specific methods awaiting owner selection: `OD-02`)*.
  * Cash on Delivery (`COD`) for eligible delivery orders when the applicable business-unit setting enables it `[Verified in Source]`. **OD-10 confirms collection-versus-remittance accounting only; it does not independently approve COD availability, supported units, or the `cod_enabled` policy.**

### 1.2 Explicit Exclusions (Deferred Beyond Phase 1)
* **Specific Payment Provider Selection:** `PayMongo`, `Maya Checkout`, `Xendit`, and `Dragonpay` are **not selected or finalized** in this specification; provider selection, API contract validation, and sandbox verification remain deferred to a later checkpoint.
* **Veterinary Clinic (`Unit 2`) & Grooming Services (`Unit 3`) Service Invoices (`service_invoice`):** Excluded (`payment.invoice_id` remains preserved per `BUSINESS_RULES.md#L241-L247, #L254-L257` for future extension) `[Documented Rule]`.
* **Automated Walk-In POS Gateway / Dynamic QR Terminal Integration (`ISS-06`):** Deferred `[Proposed Technical Design]`; walk-in cashier POS remains manual method entry, subject to transactional stock/batch safety fixes.
* **Over-the-Counter (OTC) Cash Payment Centers (`OD-03`):** `[Recommendation — Awaiting Approval]` Defer multi-day OTC clearing workflows beyond Phase 1.
* **Pre-Fulfillment Partial Refunds (`OD-11`):** Explicitly excluded in Phase 1 `[Confirmed Owner Clarification]`.
* **Multi-Branch Inventory Splits (`inventory.branch_id`, `ISS-11`):** Deferred (`inventory` remains `1:1` per `business_product_id` in `001_initial_schema.sql#L163` and `BUSINESS_RULES.md#L124-L125, #L167-L170`).
* **Refund/Return Movement Semantics (`BUSINESS_RULES.md#L194-L198, #L272-L281`):** Monetary refunds and physical returns are separate events. Rule 6 (`sale_refund`) versus Rule 8 (`return_sellable`/`return_damaged`) remains **UNRESOLVED / NOT APPROVED**. This specification does not finalize movement names, zero-delta movements, or refund-to-stock behavior; those remain blocked pending an approved amendment.
* **Variable Shipping Fee Engine (`OD-13C`):** `[Recommendation — Awaiting Approval]` Flat `₱0.00` delivery fee assumed in Phase 1 unless shipping zones are explicitly approved.

---

## 2. Existing Checkout and Payment Workflow `[Verified in Source]`

Observed runtime execution across `public/index.php`, `app/Controllers/`, `app/Models/`, and views:

1. **Shop & Cart (`ShopController.php`, `CartController.php`, `Models/Cart.php`):**
   * Catalog pages (`/shop`, `/shop/{bu}`, `/shop/{bu}/product/{id}`) compute `available = GREATEST(on_hand - committed, 0)` across all three active units (`1`, `2`, `3`).
   * `CartController::add(int $bu)` persists line items in `cart` / `cart_item` without reserving stock (consistent with `BUSINESS_RULES.md#L133, #L228` and `shop/checkout.php#L21`).
   * `Core::requireCustomer()` permits registered customers as well as authenticated employees/Super Admin (provisioning an active `customer` row if absent, consistent with dual customer/employee account linking in `BUSINESS_RULES.md#L27-L29`).
2. **Checkout (`OrderController::place(int $bu)` `#L28-L115`):**
   * Validates CSRF, `fulfillment_method IN ('pickup', 'delivery')`, and delivery address fields. No business-unit guard blocks Units `2` or `3` (`ISS-15`).
   * Locks `cart_item` + `inventory` (`FOR UPDATE`), checks `on_hand - committed >= quantity`, recalculates prices server-side, inserts `customer_order` (`order_status = 'pending'`) and `order_item`, **deletes `cart_item` (`#L98`)**, and commits (`#L99`).
   * **Gaps:** Creates **no `payment` row**, **no `payment_attempt`**, **no `checkout_intent` idempotency guard**, and **no `inventory_reservation` / `inventory.committed` update** (`ISS-04`, `ISS-16`).
3. **Pickup Fulfillment (`PetStoreReceptionistController::updatePickupStatus(int $orderId)` `#L701-L741`):**
   * Scoped to `PET_STORE_UNIT_ID = 1`.
   * Transitions pickup orders `pending -> confirmed -> ready_for_pickup -> completed` outside any DB transaction (`ISS-02`), without payment verification (`ISS-03`), without checking `inventory_reservation`, and without deducting `inventory.on_hand` or batches at handover (`ISS-04`).
4. **Delivery Fulfillment (`DeliveryController.php#L15-L629` & `PetStoreReceptionistController::rescheduleDelivery()`):**
   * `DeliveryController` enforces `DELIVERY_PERSONNEL` on `PET_STORE_UNIT_ID = 1` (or read-only `SuperAdmin`), implements `orders()`, `dispatch()`, `complete()`, and `fail()`, and calls `evaluatePaymentEligibility()` allowing `payment_status = 'completed'` or `('pending' AND payment_method IN ('cod','cash'))`, while blocking any order with a completed `'refund'` row or `'refunded'` status (`ISS-19`).
   * **Gaps:** `DeliveryController` has **0 registered routes in `public/index.php`**, **no `app/Views/delivery/` views**, and **no navigation/landing links** in `layout.php` or `AuthController::resolveEmployeeLandingFor()` (`ISS-21`). Moreover, `dispatch()`, `complete()`, and `fail()` touch **zero inventory/reservation tables**, `complete()` **does not update `payment` COD custody**, `dispatch()` permits `'pending'` orders directly (`#L407`), `evaluatePaymentEligibility()`/`orders()` inspects only `ORDER BY payment_id DESC LIMIT 1` of `'payment'` rows (`#L177-L185, #L340-L343`), and **no failed-delivery return/inspection/cancellation workflow exists** (`ISS-18`, `ISS-20`).
5. **Walk-In POS (`PetStoreReceptionistController::createSale()` `#L404-L487`):**
   * Checks `on_hand - committed >= qty` *before* `beginTransaction()` without row locks or duplicate `product_id[]` aggregation (`ISS-01`), deducts `inventory.on_hand` while ignoring `inventory_batch` (`ISS-07`), and inserts a `'completed'` `payment` row for `Cash`, `Credit Card`, `Debit Card`, `GCash`, or `Maya` without external reference verification (`ISS-06`).
6. **Refunds (`ManagerController::handleRefundDecision()` `#L3117-L3183`):**
   * Updates `refund_request` (`pending -> approved` / `rejected`), writes `Audit::log()`, and executes zero gateway refunds and zero `payment` `'refund'` rows (`ISS-10`).

---

## 3. State Machines (`[Documented Rule]` + `[Confirmed Owner Clarification]` + `[Proposed Technical Design]`)

### 3.1 `customer_order.order_status` *(Operational State; payment remains orthogonal)*
* **Existing Enum (`001_initial_schema.sql#L221` + `016_petstore_inventory_manager.sql#L39`):** `pending`, `confirmed`, `processing`, `ready_for_pickup`, `out_for_delivery`, `completed`, `cancelled`, `delivery_failed`.
* **Confirmed baseline from `BUSINESS_RULES.md#6.2`:** Cart contents do not reserve inventory. An order confirmation commits/reserves stock. Pickup deduction occurs only at actual handover. Delivery stock movements follow the dispatch/delivery lifecycle, subject to the explicit OD-09 amendment recorded below.
* **Transitions and boundaries:**
  * `pending -> confirmed`: Authorized store acceptance. In the same DB transaction, validate available stock, create FEFO reservation rows, increase `inventory.committed`, and clear/initialize the reservation expiry according to the approved payment/hold policy. **Do not create the reservation at cart checkout or merely because an order row was inserted.**
  * `pending -> cancelled`: Only an explicit authorized customer/store cancellation path or a provider-verified unpaid expiry path that is approved for the relevant order/payment state. No generic “unresolved conflict cleanup” transition is permitted. Since no stock is reserved before confirmation, cancelling a still-pending order must not decrement `committed`.
  * **Pickup:** `confirmed -> processing -> ready_for_pickup -> completed`. Deduct `on_hand` and the corresponding batch quantity, and release the reserved `committed` quantity atomically only at actual customer handover (`BUSINESS_RULES.md#L134-L136`).
  * **Delivery:** `(confirmed | processing) -> out_for_delivery -> completed | delivery_failed`. Dispatch is permitted only for store-confirmed orders with valid reservations and payment readiness under the still-unresolved payment-before-confirmation policy.
  * **Failed delivery:** A failed attempt does not automatically restock or release the reservation. Physical store return and inspection are required before any sellable quantity is restored (`BUSINESS_RULES.md#L140-L141`; OD-09). Retry cap, cancellation eligibility, and responsible roles remain unresolved owner decisions.
* **Orthogonal conflict flag (`customer_order.payment_conflict_status`):** `ENUM('none','paid_after_expiry','duplicate_payment') NOT NULL DEFAULT 'none'`. A non-`none` conflict blocks pickup handover and delivery dispatch/completion until an authorized resolution.

### 3.2 `payment.payment_status` *(Settled Financial Collection & COD Obligation Ledger)*
* **`payment_type = 'payment'`:**
  * `pending`: COD obligation created at delivery checkout. Remains `'pending'` through courier delivery (`remittance_status = 'collected_pending_remittance'`, **excluded** from cash revenue).
  * `completed`: Settled financial collection via (a) verified gateway online payment (`payment_attempt_id NOT NULL`, `gateway_provider`, `gateway_payment_id`), (b) authorized staff verification of courier COD remittance (**OD-10**: `remittance_status = 'remitted_verified'`), or (c) walk-in POS sale. **Immutable once settled** (`BUSINESS_RULES.md#L258-L260`).
  * `failed`: Terminal uncollected entry (reserved for legacy/voided COD obligation prior to collection).
  * `refunded`: Retained in `001#L402` enum strictly for legacy fixture compatibility; **all Phase 1 flows preserve `payment_status = 'completed'` on the original collection row** and record reversals strictly via child `payment_type = 'refund'` rows.
* **`payment_type = 'refund'`:**
  * `completed`: Immutable settled refund row (`original_payment_id` -> parent `'payment'`, **`payment_refund_id` -> `payment_refund`** `1:1 UNIQUE`, positive `amount`). Written **only** after gateway confirms refund completion (`payment_refund.refund_status = 'succeeded'`) or supervised offline COD/cash refund posting.

### 3.3 `payment_attempt.attempt_status` *(Non-Financial Gateway Checkout Session Lifecycle)*
* `initiating` → (`active` | `initiating_uncertain` | `failed`)
* `initiating_uncertain` → *(resolved strictly via provider retrieval/reconciliation)* → (`active` | `succeeded` | `succeeded_late_conflict` | `succeeded_duplicate_conflict` | `expired` | `failed` | `manual_resolution_required`)
* `active` → (`payment_in_progress_hold` | `succeeded` | `succeeded_late_conflict` | `succeeded_duplicate_conflict` | `expired` | `failed` | `cancelled`)
* `payment_in_progress_hold` → (`succeeded` | `succeeded_late_conflict` | `succeeded_duplicate_conflict` | `expired` | `failed`)
* `expired` → (`succeeded_late_conflict` | `succeeded_duplicate_conflict`) *(only when a verified real gateway settlement arrives after expiry)*
* **Invariants:**
  * At most **one** non-terminal attempt (`initiating`, `initiating_uncertain`, `active`, `payment_in_progress_hold`) may exist per `order_id` (`active_flag = 1`, `UNIQUE(order_id, active_flag)`).
  * No second chargeable attempt may start while any prior attempt is `'initiating_uncertain'`.
  * Each successful `payment_attempt` maps **1:1** to at most one settled `payment` row (`UNIQUE(payment.payment_attempt_id)`). Unsuccessful attempts produce **zero** `payment` rows.

### 3.4 `refund_request.request_status` & `payment_refund.refund_status`
1. `refund_request` (`012`): `pending` → `approved` (`approved_amount` = full eligible paid amount per **OD-11**) | `rejected`. Represents customer claim & manager approval only (**zero** financial reversal).
2. `payment_refund` (New execution table): `submitting` → (`pending` | `uncertain_timeout` | `succeeded` | `failed`). Only transitions to `'succeeded'` upon verified gateway settlement/inquiry, which atomically inserts **one** `payment` (`payment_type = 'refund'`, `payment_status = 'completed'`, `payment_refund_id`) row.

### 3.5 `inventory_reservation.reservation_status` *(Reservation begins at order confirmation)*
* `active`: Store-confirmed reservation held on shelf/staging (`committed += qty`; `on_hand` and `in_transit` unchanged; eligible FEFO `batch_id` pinned). This state is created at authorized order confirmation, not at cart or checkout submission.
* `in_transit`: Dispatched with courier. Under **OD-09**, dispatch deducts `on_hand` and `batch.quantity`, increases `in_transit`, and **retains `committed`** until delivery is confirmed or the physical return is inspected. This is an explicit owner clarification that supersedes the older dispatch wording in `BUSINESS_RULES.md#L137-L138`; that source document and the ERD must be amended before implementation.
* `returned_pending_inspection`: Failed delivery physically returned to store custody; stock remains quarantined and unavailable until inspection (`in_transit` and `committed` remain locked under OD-09).
* `fulfilled`: Customer handover confirmed. Pickup: `on_hand -= qty`, `batch.quantity -= qty`, `committed -= qty`. Delivery: `in_transit -= qty`, `committed -= qty`; do not deduct `on_hand` a second time.
* `cancelled`: Pre-dispatch release (`active -> cancelled`) decrements `committed` only. Post-return release is allowed only after recorded inspection; exact stock movement types and the sellable/damaged accounting implementation remain subject to the unresolved Rule 6/8 amendment. Do not implement the proposed zero-delta `return_damaged` movement as approved policy.

---

## 4. Checkout Intent, Duplicate Submission, Timeout & Uncertain-Request Recovery `[Proposed Technical Design]`

Because external HTTP calls cross a network boundary outside MySQL ACID transactions, local DB transactions alone cannot guarantee exactly-once external payment initiation. The following design preserves the documented reservation timing:

1. **Checkout intent generation (`GET /checkout/{bu}`):** Bind a CSPRNG `intent_token` to `(customer_id, business_unit_id, cart_snapshot_hash)`. Token rotation must be tied to cart-version changes or explicit expiry; a routine refresh or second tab must not invalidate an otherwise valid in-flight submission unexpectedly.
2. **Phase A — create the pending order (short local transaction):** Lock the intent and cart rows, revalidate product status/prices and current sellable availability, create `customer_order(order_status='pending')` and `order_item`, mark the intent `order_created`, and delete the submitted cart rows. **Do not create `inventory_reservation` rows or increase `inventory.committed` here.** The cart itself remains non-reserving, and the confirmed order acceptance event is the reservation boundary in `BUSINESS_RULES.md#6.2`.
3. **Order confirmation / reservation transaction:** When an authorized store actor confirms the pending order, lock the order, inventory, eligible FEFO batches, and relevant reservation rows; recheck stock; create the reservation rows and increase `inventory.committed` atomically with `order_status='confirmed'`. If stock is no longer available, confirmation must fail without partial reservation. This reservation step is independent of whether payment is already settled; the policy governing payment-before-confirmation remains unresolved (§17.2).
4. **Payment initiation sequencing:** Do not assume that online payment must precede or follow store confirmation. The owner decision `OD-04`/`OD-08` remains open. The architecture may create a payment attempt for a pending order only after the owner selects the sequence and its reservation/expiry consequences. The same applies to pickup prepayment. For COD, create a pending COD obligation only if COD is enabled for that unit and fulfillment method; do not reserve stock at checkout. Store confirmation creates the reservation. COD reservation expiry/acceptance rules remain subject to the open order-confirmation and cancellation policy.
5. **Phase B — external session initiation (only after sequencing is approved):** Commit the local attempt row before outbound provider HTTP. Call `PaymentGatewayInterface::createCheckoutSession()` outside DB locks, using a provider idempotency key where supported.
   * **Successful session response:** Persist `gateway_session_id`, `checkout_url`, and `attempt_status='active'`.
   * **Provider-confirmed terminal rejection:** Mark `attempt_status='failed'` only when the provider response proves no session/charge was created. A generic HTTP `5xx`, timeout, connection reset, or lost response is **ambiguous**, not a definitive failure.
   * **Ambiguous outcome:** Persist `attempt_status='initiating_uncertain'`; block creation of another chargeable attempt until provider retrieval/reconciliation resolves the outcome or staff completes documented manual resolution.
6. **Recovery:** On reload, retry click, or CLI sweep, query provider session/reference/attempt identifiers outside DB locks, then use a short transaction to persist `active`, verified settlement, terminal `failed`, or `manual_resolution_required`. Never claim exactly-once initiation unless provider idempotency semantics and retention are verified in sandbox.

---

## 5. Payment Ledger Semantics, Attempt-to-Ledger Mapping & COD Remittance

### 5.1 Ledger Rules (`[Documented Rule]` + `[OD-10]`)
* **Parent Exclusivity (`BUSINESS_RULES.md#L254-L257`):** Every `payment` row references **exactly one** of `invoice_id`, `sale_id`, or `order_id` (`CHECK` constraint).
* **Original Collection Preservation (`#L258-L260`):** Completed collections (`payment_type = 'payment'`, `payment_status = 'completed'`) are immutable financial facts even if later refunded.
* **Refund Children:** Executed refunds insert a child `payment` row (`payment_type = 'refund'`, `original_payment_id = <parent>`, `payment_refund_id = <exec>`, positive `amount`, `payment_status = 'completed'`).

### 5.2 Explicit Payment Attempt-to-Ledger Relationship (`[Proposed Technical Design]`)
1. **1:1 Settlement Link:** `payment` includes **`payment_attempt_id INT UNSIGNED NULL UNIQUE FK`** referencing `payment_attempt(attempt_id)`. For gateway-originated collection rows (`payment_type='payment'`), `payment_attempt_id`, `gateway_provider`, and `gateway_payment_id` are all required. For non-gateway rows such as COD/POS, these gateway fields remain NULL. Enforce the invariant with a DB `CHECK` where supported and repeat it in the service layer; also validate that the attempt's `order_id` equals the payment's `order_id` because a simple FK cannot enforce that cross-row equality.
2. **Provider Transaction Identifier Storage:**
   * `payment_attempt` stores initiation/checkout identifiers: `gateway_provider`, `gateway_session_id` (`UNIQUE(gateway_provider, gateway_session_id)`), `attempt_uuid` (`UNIQUE`), and `reference_number` (`UNIQUE`).
   * `payment` stores settled financial identifiers: `payment_attempt_id` (`UNIQUE`), `gateway_provider`, **`gateway_payment_id`** (`UNIQUE(gateway_provider, gateway_payment_id)`), `currency = 'PHP'`, `amount`, `paid_at`.
3. **Retries & Multiple Attempts:** Each checkout retry on an `order_id` creates a new `payment_attempt` (`attempt_number = 1, 2, 3...`, `UNIQUE(order_id, attempt_number)`). Failed, cancelled, or expired attempts create **no** `payment` rows.
4. **Duplicate Financial Posting Prevention:** Database uniqueness (`UNIQUE(payment_attempt_id)` + `UNIQUE(gateway_provider, gateway_payment_id)`) combined with row-level `FOR UPDATE` locking ensures concurrent webhooks, return-URL hits, and CLI reconcilers can **never** post two `'payment'` rows for the same attempt or gateway transaction.
5. **Detection & Reconciliation of Late or Multiple Successful Attempts:**
   * **Replayed Same Attempt:** Caught cleanly by `attempt_status = 'succeeded'` and existing `payment` for `payment_attempt_id` → no second insert, acknowledge `200 OK`.
   * **Late Success After Order Cancellation (`paid_after_expiry`):** If a valid §7.2-verified settlement arrives for a cancelled order, record the real collection in the payment ledger, do not reinstate the order or re-lock stock automatically, set `payment_conflict_status='paid_after_expiry'`, and escalate for owner-approved resolution. **Keeping the order cancelled is a conservative technical recommendation; automatically creating a `refund_request` is not an approved rule while OD-06 remains unresolved.**
   * **Multiple Successful Payments on Same Order (`duplicate_payment`):** If a verified settlement arrives while another settled collection already exists for the order, transition the new attempt to `'succeeded_duplicate_conflict'`, record the additional settled `payment` row under its unique `payment_attempt_id` (never discard actual incoming funds), set `payment_conflict_status='duplicate_payment'`, block fulfillment, and escalate via `Audit::record()`, Manager alert, and `MailService`. **Do not automatically create a `refund_request` until OD-06/§17.2 approves that policy.**

### 5.3 COD Collection & Remittance Lifecycle (`[Confirmed Owner Clarification: OD-10]`)
1. **Checkout (`OrderController` / `CheckoutService`):** If COD is enabled for the applicable unit and delivery method, create a `payment` row (`payment_method='cod'`, `payment_status='pending'`, `remittance_status='awaiting_delivery'`). This is a pending obligation, not settled cash revenue. Creating this row does not reserve inventory.
2. **Store confirmation:** The normal order-confirmation transaction creates the inventory reservation and increases `committed` as required by `BUSINESS_RULES.md §6.2`. COD-specific timeout/cancellation policy remains unresolved; do not assume an unlimited hold.
3. **Courier customer delivery (`DeliveryController::complete()`):** Permit completion only after the courier records actual handover and confirms collection of the exact COD amount due. In one transaction, close the `in_transit` reservation, set `customer_order.order_status='completed'`, record `collected_amount`, `collected_by_employee_id`, `collected_at`, and `remittance_status='collected_pending_remittance'`, while **leaving `payment.payment_status='pending'`**. Reject missing/incorrect collection evidence. Short/over-collection handling requires an owner rule and must not be silently normalized.
4. **Authorized remittance verification:** In a locked transaction, a staff verifier other than the collecting courier confirms the cash handover, records verifier and timestamp, sets `remittance_status='remitted_verified'`, and transitions `payment_status` from `pending` to `completed`, with `Audit::record()`. The exact eligible verifier-role matrix and exception handling remain unresolved (§17.2).
5. **Cancellation before collection:** Any authorized cancellation path that voids an uncollected COD order must close the pending COD obligation explicitly (for example, a terminal `failed`/voided state plus audit reason); it must not leave an open `awaiting_delivery` receivable. The precise cancellation triggers and status semantics require owner approval.

### 5.4 Safe SQL Join Rule (`ISS-05`, `[Proposed Technical Design]`)
Filtering `LEFT JOIN payment` by `payment_type = 'payment'` **still duplicates parent `customer_order` / `sale` rows** whenever >1 `'payment'` row exists (e.g., `duplicate_payment` conflict or split legacy entries). Every list/summary query on `customer_order` or `sale` MUST join a **grouped subquery (`GROUP BY order_id` / `GROUP BY sale_id`)** or correlated scalar aggregate—never an ungrouped `LEFT JOIN payment`.

---

## 6. Inventory Lifecycle, In-Transit Accounting & Mixed Return Inspection

`[Documented Rule]` + `[Confirmed Owner Clarification: OD-09]`

### 6.1 Quantity Model — Documented Baseline and OD-09 Amendment
* **`on_hand`:** Physical stock currently on the store's sellable shelf/staging.
* **`committed`:** Under `BUSINESS_RULES.md`, the sum of quantities reserved for confirmed orders. A cart and an unconfirmed pending order do not reserve stock.
* **`in_transit`:** Dispatched stock not currently on the store shelf. Under **OD-09**, this includes physically returned stock awaiting inspection until inspection resolves its disposition.
* **Reservation timing (documented rule):** Increase `committed` only when the store confirms the order, atomically with reservation creation. Do not reserve during cart or checkout submission.
* **Delivery dispatch conflict (explicit amendment required):** `BUSINESS_RULES.md#L137-L138` currently says dispatch releases `committed`; **OD-09 is the later confirmed owner clarification** and requires `committed` to remain held through courier custody, then be released on confirmed delivery or after inspected physical return. The owner decision supersedes those two older lines for the intended design, but `BUSINESS_RULES.md` and the ERD must be amended before implementation so there is one authoritative rule.
* **Availability under OD-09:** Because `committed` includes courier/quarantine custody, use `Available = on_hand - (committed - in_transit)` or equivalently `(on_hand + in_transit) - committed`. This formula is a derived technical consequence of OD-09, not the literal formula currently written in `BUSINESS_RULES.md#L126-L129`; amend that section alongside OD-09. Enforce `committed >= in_transit >= 0` and nonnegative `on_hand`.
* **Quarantine:** Returned but uninspected units are not sellable. They remain included in `in_transit` and `committed`, with no increase to `on_hand` until inspection is completed.

### 6.2 Lifecycle Transition Matrix *(illustrative arithmetic; movement labels remain subject to Rule 6/8 approval)*

**Case A — 2-unit order after confirmation (`on_hand=10`, `in_transit=0`, `committed=0` initially):**

| Lifecycle Event | Reservation | `on_hand` | `in_transit` | `committed` | `Available` | Batch quantity |
|---|---|---:|---:|---:|---:|---:|
| Cart / checkout submission | None | 10 | 0 | 0 | 10 | 10 |
| Store confirms order | `active` | 10 | 0 | 2 | 8 | 10 |
| Pickup handover | `fulfilled` | 8 (-2) | 0 | 0 (-2) | 8 | 8 (-2) |
| Delivery dispatch (OD-09) | `in_transit` | 8 (-2) | 2 (+2) | 2 (retained) | 8 | 8 (-2) |
| Delivery confirmed | `fulfilled` | 8 | 0 (-2) | 0 (-2) | 8 | 8 (no second deduction) |
| Delivery failed; courier retains parcel | `in_transit` | 8 | 2 | 2 | 8 | 8 |
| Physical return received, awaiting inspection | `returned_pending_inspection` | 8 | 2 | 2 | 8 | 8 |
| Pre-dispatch cancellation | `cancelled` | 10 | 0 | 0 (-2) | 10 | 10 |

**Case B — Mixed inspection:** If 5 units were dispatched and physically returned, then inspection finds 3 sellable and 2 damaged, the application must record the inspection quantities per reservation/batch and atomically resolve custody. Only the 3 sellable units may re-enter sellable `on_hand`/batch quantity; the 2 damaged units must be accounted for as damaged/lost. The precise `inventory_movement` rows and movement names are **not approved** until Rule 6/8 is reconciled in `BUSINESS_RULES.md`. In particular, the currently proposed zero-delta `return_damaged` row is not an approved schema rule and must not be frozen.

**Atomicity and audit requirements:** Return receipt and inspection must each be transactional, actor-attributed, timestamped, and idempotent. A reservation cannot be cancelled, re-dispatched, or released twice while inspection is pending. For FEFO splits, inspection quantities must reconcile per original reservation/batch and satisfy `sellable_quantity + damaged_quantity = reserved_quantity` when inspection is complete.

### 6.3 Batch FEFO & Walk-In POS Concurrency (`ISS-01`, `ISS-07`, `[Documented Rule]`)
* **Online FEFO:** Run allocation only during store confirmation. Lock inventory and eligible batches, ordered by earliest expiry then stable batch ID; allocate across batches as needed. Do not decrement physical batch quantity when creating a reservation.
* **Date boundary:** `CURDATE_MANILA` is pseudocode, not a MySQL function. Compute the current Manila calendar date in application code or use an explicitly defined timezone conversion to derive a UTC half-open boundary; pass a concrete date/time parameter to SQL. Do not ship `CURDATE_MANILA` as executable SQL.
* **Walk-In POS:** Aggregate duplicate product lines, lock inventory/batches, and deduct only unreserved stock in the same transaction as sale/payment/movement writes. Under OD-09, shelf-available stock is `on_hand - (committed - in_transit)`; because in-transit stock has already left `batch.quantity`, batch-level POS availability must subtract only active on-shelf reservations from the batch quantity.

---

## 7. Webhook Inbox, Durable Retry Handling & Settlement Verification `[Proposed Technical Design]`

### 7.1 Two-Phase Durable Webhook Processing & Concurrency Control
A valid signature guarantees authenticity of the transport payload only; webhook processing MUST persist before acknowledgement and must never treat mere row existence as completed processing:

1. **Pre-DB Validation:** Require HTTPS; validate raw unmodified `php://input` cryptographic signature (`hash_equals()`), replay timestamp window (if provided), and environment scope. Reject invalid signatures with HTTP `400`/`401` before any DB write.
2. **Phase 1 — Durable Persist & Concurrency Claim (Short Tx 1):**
   * Open Tx 1 and attempt `INSERT INTO payment_webhook_event` (`gateway_provider`, `provider_event_id`, `event_type`, `signature_valid = 1`, `raw_payload`, `processing_status = 'received'`, `attempt_count = 1`, `received_at = NOW_UTC()`).
   * **Persistence Failure:** Roll back and return **HTTP `500` (retryable)** — never return `200 OK` if persistence fails.
   * **Duplicate Delivery (`UNIQUE(gateway_provider, provider_event_id)` hit):** Lock the existing row (`SELECT ... FROM payment_webhook_event WHERE gateway_provider = ? AND provider_event_id = ? FOR UPDATE`) and inspect `processing_status`:
     * `'processed'`, `'ignored_out_of_order'`, or `'rejected_mismatch'` → Commit and return **`200 OK`** without repeating financial effects.
     * `'processing'` with `processing_lease_until > NOW_UTC()` → Active concurrent worker holds lease; commit cleanly and return **`503 Service Unavailable`** (retryable; neither duplicate-processed nor falsely acked).
     * `'received'`, `'retry_needed'`, or stale `'processing'` (`processing_lease_until <= NOW_UTC()`) → Eligible for safe reprocessing!
   * Claim the row by updating `processing_status = 'processing'`, `processing_lease_until = NOW_UTC() + 60s`, `attempt_count = attempt_count + 1`, `last_attempted_at = NOW_UTC()`, and **commit Tx 1** before executing domain mutations.
3. **Phase 2 — Domain Settlement Execution (Short Tx 2):**
   * Open Tx 2, lock target `customer_order`, `payment_attempt`, `payment`, `payment_refund`, and `payment_webhook_event` (`FOR UPDATE`), execute **§7.2 settlement verification** (when event asserts payment completion), apply ledger/status/conflict changes + `Audit::record()` *(zero outbound HTTP or SMTP inside Tx 2)*, update `payment_webhook_event` (`processing_status = 'processed' | 'ignored_out_of_order' | 'rejected_mismatch'`, `processed_at = NOW_UTC()`, `processing_lease_until = NULL`, `last_error = NULL`), commit Tx 2, dispatch out-of-band emails if queued, and return **`200 OK`**.
   * **Processing Exception / DB Lock Timeout in Tx 2:** Roll back Tx 2 domain writes completely; attempt a short cleanup Tx 2b to mark `retry_needed`, clear the lease, and store a bounded error diagnostic, then return **HTTP `500`**. If Tx 2b also fails, leave the durable row in `processing`; its lease expiry makes it reclaimable by the mandatory CLI reconciler. Do not rely solely on provider retries: some providers may not retry `503`/`500` consistently, so the CLI reconciler is a required recovery path. Preserve bounded attempt/error history, not only the latest error string.
4. **CLI Reconciler Recovery (`ISS-17`):** Periodic PHP CLI sweep (`tools/php83/php.exe`) scans `payment_webhook_event WHERE processing_status IN ('received', 'retry_needed') OR (processing_status = 'processing' AND processing_lease_until <= NOW_UTC())` and runs the same Phase 1 claim + Phase 2 processor (escalating rows exceeding `MAX_WEBHOOK_ATTEMPTS` to `'manual_review_required'` + Manager alert).

### 7.2 Mandatory Pre-Settlement Verification Invariants *(Applicable to Webhooks, Return-URL Checks & CLI Reconcilers)*
Before recording ANY `payment` (`payment_status = 'completed'`) or marking `payment_attempt` `'succeeded'`, verify all **six** conditions inside Tx 2 against authoritative payload/retrieved session data:
1. **Merchant / Account Identity:** Payload merchant/account ID (and unit credential scope) matches PawCariño's configured active `PAYMENT_MERCHANT_ACCOUNT_ID`.
2. **Expected Order & Payment Attempt:** Identifiers (`reference_number`, `gateway_session_id`, `attempt_uuid`, `order_id`) resolve deterministically to an existing `payment_attempt` and its `customer_order` (`business_unit_id = 1`).
3. **Exact Expected Amount (Minor Units):** Paid amount in integer centavos equals `ROUND(payment_attempt.expected_amount * 100)` exactly.
4. **Currency:** Paid currency equals `'PHP'` (`payment_attempt.expected_currency`).
5. **Actual Provider Settlement Status:** Status indicates settled completion (`paid` / `settled`), NOT `pending`, `session_created`, `awaiting_action`, `authorized_uncaptured`, `expired`, or `failed`. *(Where provider webhook payloads require follow-up `GET` confirmation, perform it prior to Tx 2—`[Provider-Dependent — Unverified]`.)*
6. **Environment Mode (`sandbox` vs `live`):** Event/session mode strictly matches configured `PAYMENT_MODE`; sandbox events MUST NEVER mark live orders paid or vice versa.

* **Mismatch Handling:** Never insert a completed `payment` row and never mark the order paid. Roll back any tentative changes, persist `payment_webhook_event` as `'rejected_mismatch'` with `mismatch_reason` (e.g., `amount_mismatch`, `currency_mismatch`, `env_mode_mismatch`, `merchant_mismatch`, `unknown_attempt`, `non_settled_status`), flag `payment_attempt` for `'manual_resolution_required'` if an external payment mismatch affects an active checkout, write `Audit::record()`, trigger persistent Manager Dashboard alert + out-of-band `MailService` alert, and return `200 OK` (for deterministic logged payload rejection so providers do not flood retries on the identical invalid payload while finance investigates).

---

## 8. Payment Expiry (Lock-Free External HTTP), Late Payment & Conflict Handling

### 8.1 Safe Lease-Based Reservation Expiry Sequence `[Proposed Technical Design]`
This worker applies only to a **confirmed order with an active reservation and an approved finite hold/expiry policy**. A pending, unconfirmed order has no reservation and must not be stock-released by this worker.

1. **Claim in short Tx 1:** Select confirmed orders whose non-NULL `reservation_expires_at` has elapsed and whose expiry lease is absent/stale; lock with `FOR UPDATE SKIP LOCKED`, claim a lease token, and read the relevant attempt/session identifiers. Do not hold DB locks during provider HTTP.
2. **Commit claim:** Commit Tx 1 and release all locks before provider inquiry.
3. **Provider inquiry:** Retrieve session status outside the transaction. If status is ambiguous or network inquiry fails, fail closed and leave the reservation intact for retry. Explicit provider session expiration is provider-dependent and must be sandbox-verified.
4. **Finalize in Tx 2:** Lock in canonical order (`customer_order` → `payment_attempt` → `payment` → `inventory_reservation` → `inventory`) and re-check order status, settlement rows, attempt status, reservation state, and lease token.
5. **Abort conditions:** Abort release if the order progressed, any valid settlement exists, any newer attempt is active/uncertain, or provider status is not conclusively unpaid/expired. If the provider reports paid, run §7.2 settlement verification and apply the unresolved late-payment policy without releasing stock.
6. **Release:** Only after owner-approved expiry policy, provider-confirmed unpaid/expired status, confirmed order still eligible for expiry, and no active retry, transition active reservation rows to cancelled and decrement `committed` atomically with audit logging. The exact TTL and extension ceiling remain owner/provider-dependent; any `payment_in_progress_hold` extension must have a finite maximum and absolute expiry.

**COD:** A COD order has no gateway session to expire. Do not set `reservation_expires_at=NULL` and thereby imply an unlimited stock reservation. The order is unreserved while pending; after store confirmation, any COD reservation timeout/cancellation rule must be explicitly approved. No automatic COD expiry is assumed here.

### 8.2 Late-Payment & Duplicate-Payment Conflicts (`OD-06`, `ISS-23`)
When a verified settlement completes after cancellation (`paid_after_expiry`) or alongside an existing settled `'payment'` (`duplicate_payment`):
1. Insert settled `payment` (`payment_attempt_id`, `'completed'`) so incoming customer cash is never absent from the financial ledger.
2. Never re-lock or oversell stock (`order_status` stays `'cancelled'` on `paid_after_expiry`).
3. Set `customer_order.payment_conflict_status` (`'paid_after_expiry'` or `'duplicate_payment'`) and block fulfillment (`OrderPaymentEligibilityService`).
4. Escalate for manual/authorized resolution. Automatically creating a pending `refund_request` is **not approved** until OD-06 is resolved; if approved later, it must reference the exact payment row and must not execute a refund automatically.
5. Escalate via `Audit::record()`, persistent Manager Dashboard conflict banner, customer `/orders/{id}` notice, and out-of-band `MailService` email.

---

## 9. Refund Request, Approval, Execution & Financial Recording (`OD-05`, `OD-11`, `ISS-10`)

1. **Four Distinct Lifecycle Objects:**
   * `refund_request` (`pending`): Customer claim & supporting evidence (`012`).
   * `refund_request` (`approved` / `rejected`): Manager decision (`approved_amount`, `decided_by_employee_id`). **Zero financial movement occurs upon approval.**
   * `payment_refund` (`submitting` → `pending` | `uncertain_timeout` → `succeeded` | `failed`): Outbound gateway execution attempt carrying `refund_idempotency_key` & `gateway_refund_id`.
   * `payment` (`payment_type = 'refund'`, `payment_status = 'completed'`, `original_payment_id = <parent>`, **`payment_refund_id = <exec>`**): Immutable accounting reversal posted strictly upon confirmed `'succeeded'` refund settlement (`BUSINESS_RULES.md#L258-L264`).
2. **Phase 1 Policy Scope (`[Confirmed Owner Clarification: OD-11]`):**
   * Pre-fulfillment partial refunds are **excluded**. For Phase 1, a full refund request follows the existing `refund_request` approval process where the request is eligible under the current workflow. The automatic eligibility of late-payment and duplicate-payment conflict cases remains subject to OD-06; do not assume those cases are already covered by an approved refund policy.
   * **No Net-Balance-Alone Shortcut:** Never infer fulfillment eligibility from a residual net balance alone. A full-refund request/approved/executing/succeeded state must block fulfillment where applicable. The existing blanket block on any completed refund is not by itself a confirmed defect under Phase 1 because partial refunds are excluded; future partial-refund behavior is deferred (`ISS-19`).
3. **Four-Layer Duplicate Refund Protection (`[Proposed Technical Design]`):**
   * **Layer 1 (Row Lock & Exposure Check):** Before creating `payment_refund`, lock parent `payment` (`FOR UPDATE`), verify `gateway_payment_id IS NOT NULL`, check parent `payment_status = 'completed'`, and compute `remaining_refundable = orig.amount - SUM(settled_refunds + in_flight_payment_refunds ['submitting','pending','uncertain_timeout'])`.
   * **Layer 2 (Single Active Execution Key):** `payment_refund` enforces `UNIQUE(refund_idempotency_key)`, `UNIQUE(gateway_provider, gateway_refund_id)`, and `UNIQUE(refund_request_id, active_refund_flag)` (`active_refund_flag = 1` whenever `refund_status != 'failed'`), preventing concurrent double-clicks on an approved request.
   * **Layer 3 (`1:1` Ledger Constraint):** **`payment.payment_refund_id INT UNSIGNED NULL UNIQUE FK`** forbids inserting more than one `'refund'` ledger entry per `payment_refund`.
   * **Layer 4 (Cumulative Guard):** Both transaction logic and verification forbid `total_refunded > original_payment.amount` (`BUSINESS_RULES.md#L263-L264`).
4. **Decoupled HTTP Execution (`[Proposed Technical Design — OD-05]`):** Stage 2 locks & commits `payment_refund` (`'submitting'`), invokes `PaymentGatewayInterface::submitRefund()` **outside** DB locks, records `'pending'` or `'uncertain_timeout'` (blocking duplicate submissions until reconciled), and posts the `'refund'` row in `payment` only upon verified webhook or inquiry `'succeeded'`. *(Offline COD/cash refunds record the `'refund'` `payment` row through a supervised manual cash-refund flow with audit logging.)*

---

## 10. Revenue and Reporting Definitions (`ISS-22`, `[Proposed Technical Design]`)

Replaces misleading order-sum "Revenue" KPIs (`SuperAdminController#L44,#L111-L114`) and UTC `CURDATE()` POS sums (`PetStoreReceptionistController#L113`):

1. **Settled Payments (Gross Collected Revenue):** `SUM(p.amount)` where `p.payment_type = 'payment' AND p.payment_status IN ('completed', 'refunded')`. *(Includes verified online gateway payments, walk-in POS sales, and remittance-verified COD.)*
2. **Settled Refunds (Confirmed Refunds Executed):** `SUM(p.amount)` where `p.payment_type = 'refund' AND p.payment_status = 'completed'`. *(Excludes pending/approved `refund_request` and unconfirmed `payment_refund`.)*
3. **Net Collected Revenue:** `Settled Payments - Settled Refunds`.
4. **Pending COD Receivables *(Non-Cash Obligation — Excluded from Collected Revenue)*:** `SUM(p.amount)` where `p.payment_type = 'payment' AND p.payment_method = 'cod' AND p.payment_status = 'pending'`, split into:
   * `remittance_status = 'awaiting_delivery'` *(in transit / uncollected)*
   * `remittance_status = 'collected_pending_remittance'` *(courier holds cash awaiting authorized verification per **OD-10**)*
5. **Booked Order Value *(Non-Financial Operational GMV)*:** `SUM(co.total_amount)` for non-`cancelled` orders — must always be labeled **"Booked Order Value (GMV)"**, never "Revenue".
6. **Manila-Day Timezone Boundaries (`ISS-08`):** Persist timestamps in UTC `DATETIME`/`TIMESTAMP`; query daily KPIs (`Daily Total Sales`, etc.) using explicit `Asia/Manila` `[start_utc, end_utc)` bounds rather than MySQL `DATE(...) = CURDATE()`.

---

## 11. Authentication, Authorization, Audit Logging & Security Requirements

* **Customer Isolation (`Core::requireCustomer()`, `OrderController`):** Ownership checks (`WHERE order_id = ? AND customer_id = ?`) and `CSRF::check()` on all state-changing routes (`BUSINESS_RULES.md#L95-L96`).
* **Employee Self-Order Separation Guard (`[Proposed Technical Design]`):** Because an employee account may hold a linked customer identity (`BUSINESS_RULES.md#L27-L29`), controllers must block an employee from approving their own `refund_request`, verifying their own COD remittance, inspecting their own order's returns, or confirming pickup/delivery on their own `customer_id` order (`actor.user_id != order_customer.user_id`).
* **Staff Role Boundaries (`roles.sql`, `permissions.sql`, `DeliveryController`):**
  * **`DELIVERY_PERSONNEL` (`Unit 1`):** Dispatches confirmed delivery orders, marks delivery `completed` + records **COD collection custody** (`OD-10`), and reports `delivery_failed`. Prohibited from payment settings edits, self-verifying COD remittance, store return inspection (`ISS-18`), and order cancellation.
  * **`RECEPTIONIST` (`Unit 1`):** Confirms pickup fulfillment strictly upon customer handover (`ready_for_pickup -> completed`) gated on payment/conflict checks, reschedules `delivery_failed` within retry cap, and operates walk-in POS.
  * **`INVENTORY_MANAGER` (`Unit 1`):** Confirms physical return receipt (`returned_pending_inspection`) and records per-reservation sellable/damaged inspection splits (`OD-09`, `ISS-18`).
  * **`BUSINESS_MANAGER` (`Unit 1`):** Manages unit payment toggles (`ISS-14`), approves/rejects `refund_request`, authorizes post-failure order cancellations, resolves `paid_after_expiry`/`duplicate_payment` conflicts, executes gateway refunds, and verifies COD remittance *(until exact remittance role scope in §17.2 is finalized)*.
  * **`SUPER_ADMINISTRATOR`:** Read-only oversight unless explicit operational role assignment applies (`DeliveryController.php#L99-L106`).
* **Cryptographic & Secret Hygiene:** Strict TLS (`CURLOPT_SSL_VERIFYPEER = true`), `hash_equals()` webhook verification on untouched `php://input`, zero raw secrets/keys/card PANs persisted in DB or `audit_log`, atomic `Audit::record($db, ...)` inside domain transactions.

---

## 12. Configuration and Environment Separation

* **Environment Secrets (`EnvConfig.php`, `ISS-13`):** Migrate [config/database.php#L9](file:///d:/VETGRPETproj/config/database.php#L9) from `parse_ini_file()` to `EnvConfig` so DB and gateway credentials (`PAYMENT_GATEWAY`, `PAYMENT_MODE=sandbox|live`, `PAYMENT_MERCHANT_ACCOUNT_ID`, secret keys, webhook secrets, timeouts) load through one fail-closed path; sanitize `.env.example` placeholders only.
* **`business_settings` Separation (`011#L109-L123`, `ISS-14`):** Keep `gcash_mobile`, `gcash_verified`, `maya_mobile`, `maya_verified`, and `otp_verification` (`OtpService`) strictly for existing merchant-wallet SMS OTP; add dedicated online-gateway availability toggles and fix `ManagerController::updatePaymentSettings()` (`#L4910-L4953`) to use `$routeUnitId`.
* **Currency Display Defect (`ISS-12`):** Update `money()`/`peso()` in `app/bootstrap.php#L23-L31` from `'$'` to `'₱'` *(confirmed UI defect; KYC dependency `[Provider-Dependent — Unverified]`)*.

---

## 13. Required Database Changes and Migration Dependencies (`Migration 020+`)

Next migration after `019_create_customer_registration_pending.sql` (`[Verified in Source]`): **Migration `020+`** (with paired `down/020_*.down.sql` scripts, explicit UTC `DATETIME` timestamps, InnoDB, `utf8mb4_unicode_ci`):

1. **`inventory` & `inventory_reservation` (`001#L161-L279`, `OD-09`, Technical Issue `1.D`):**
   * `inventory`: Add `in_transit INT NOT NULL DEFAULT 0 AFTER committed` + `CHECK (on_hand >= 0 AND committed >= 0 AND in_transit >= 0 AND committed >= in_transit)`.
   * `inventory_reservation`: Extend `reservation_status` for store reservation, courier custody, return quarantine, fulfillment, and cancellation. Proposed inspection fields may include `sellable_quantity`, `damaged_quantity`, `inspection_outcome`, receipt/inspection actors and timestamps. Enforce quantity reconciliation once inspection is complete. **Do not finalize return movement types or zero-delta damaged rows until Rule 6/8 is formally amended and approved.**
   * `inventory_movement`: Preserve the documented invariant that stock-changing movement quantities are negative for outbound and positive for inbound. Exact dispatch/return movement names and whether a non-stock-changing inspection event belongs in this table remain unresolved under Rule 6/8; keep inspection/audit data separate until approved.
2. **`customer_order` (`001#L214-L234` + `016#L39-L64`):**
   * Add `reservation_expires_at DATETIME NULL`, `expiry_lease_until DATETIME NULL`, `expiry_lease_token CHAR(36) NULL`, `payment_conflict_status ENUM('none','paid_after_expiry','duplicate_payment') NOT NULL DEFAULT 'none'`, `delivery_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0`, `returned_confirmed_by_employee_id INT UNSIGNED NULL`, `returned_confirmed_at DATETIME NULL`.
3. **`payment_attempt` (New Table):**
   * `attempt_id`, `order_id FK`, `attempt_number INT UNSIGNED NOT NULL`, `attempt_uuid CHAR(36) UNIQUE`, `reference_number VARCHAR(80) UNIQUE`, `gateway_provider VARCHAR(40) NOT NULL`, `gateway_session_id VARCHAR(120) NULL`, `checkout_url TEXT NULL`, `expected_amount DECIMAL(12,2) NOT NULL`, `expected_currency CHAR(3) NOT NULL DEFAULT 'PHP'`, `attempt_status ENUM('initiating','initiating_uncertain','active','payment_in_progress_hold','succeeded','succeeded_late_conflict','succeeded_duplicate_conflict','expired','failed','cancelled','manual_resolution_required')`, `active_flag TINYINT GENERATED ALWAYS AS (CASE WHEN attempt_status IN ('initiating','initiating_uncertain','active','payment_in_progress_hold') THEN 1 ELSE NULL END) STORED`, `UNIQUE(order_id, attempt_number)`, `UNIQUE(order_id, active_flag)`, `UNIQUE(gateway_provider, gateway_session_id)`, `expires_at DATETIME NULL`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`, `last_error VARCHAR(255) NULL`, and provider-specific transaction/reference fields only where the provider actually supplies them.
4. **`payment_refund` (New Table):**
   * `payment_refund_id`, `refund_request_id FK NULL`, `original_payment_id FK NOT NULL`, `refund_idempotency_key CHAR(36) UNIQUE`, `gateway_provider VARCHAR(40) NOT NULL`, `gateway_refund_id VARCHAR(120) NULL`, `amount DECIMAL(12,2) NOT NULL`, `refund_status ENUM('submitting','pending','uncertain_timeout','succeeded','failed')`, `active_refund_flag TINYINT GENERATED ALWAYS AS (CASE WHEN refund_status IN ('submitting','pending','uncertain_timeout','succeeded') THEN 1 ELSE NULL END) STORED`, `UNIQUE(refund_request_id, active_refund_flag)`, `UNIQUE(gateway_provider, gateway_refund_id)`, `initiated_by_employee_id INT UNSIGNED NULL`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`.
5. **`payment` (`001#L392-L409`, `OD-10`, Technical Issues `1.B`, `1.C`, `§9`):**
   * Add **`payment_attempt_id INT UNSIGNED NULL UNIQUE FK`** (`-> payment_attempt(attempt_id)`), **`payment_refund_id INT UNSIGNED NULL UNIQUE FK`** (`-> payment_refund(payment_refund_id)`), `gateway_provider VARCHAR(40) NULL`, `gateway_payment_id VARCHAR(120) NULL`, `currency CHAR(3) NOT NULL DEFAULT 'PHP'`, `paid_at DATETIME NULL`.
   * Add COD remittance columns: `remittance_status ENUM('not_applicable','awaiting_delivery','collected_pending_remittance','remitted_verified') NOT NULL DEFAULT 'not_applicable'`, `collected_amount DECIMAL(12,2) NULL`, `collected_by_employee_id INT UNSIGNED NULL`, `collected_at DATETIME NULL`, `remitted_verified_by_employee_id INT UNSIGNED NULL`, `remitted_verified_at DATETIME NULL`.
   * Add `UNIQUE KEY uk_payment_provider_tx (gateway_provider, gateway_payment_id)`, `CHECK (amount > 0)`, and the exactly-one-parent check. Add a gateway collection invariant: when `payment_type='payment'` and `gateway_provider IS NOT NULL`, require `payment_attempt_id` and `gateway_payment_id`; if `payment_attempt_id IS NOT NULL`, require gateway provider/payment ID. Validate attempt/order ID equality in the service transaction because a simple FK cannot enforce it. Do not require a provider transaction ID if the selected provider does not expose one; map its canonical settlement identifier after provider selection.
6. **`payment_webhook_event` (New Durable Inbox Table, Technical Issue `1.A`):**
   * `webhook_event_id`, `gateway_provider VARCHAR(40)`, `provider_event_id VARCHAR(120)`, `UNIQUE(gateway_provider, provider_event_id)`, `event_type VARCHAR(80)`, `signature_valid TINYINT(1) NOT NULL`, `raw_payload MEDIUMTEXT NOT NULL`, `processing_status ENUM('received','processing','processed','retry_needed','ignored_out_of_order','rejected_mismatch','manual_review_required') NOT NULL`, `processing_lease_until DATETIME NULL`, `attempt_count INT UNSIGNED NOT NULL DEFAULT 1`, `last_attempted_at DATETIME NULL`, `last_error VARCHAR(255) NULL`, `error_history` or a bounded diagnostic/event log, `mismatch_reason VARCHAR(100) NULL`, `related_order_id INT UNSIGNED NULL`, `received_at DATETIME NOT NULL`, `processed_at DATETIME NULL`.
7. **`checkout_intent` (`TD-03`) & `business_settings` (`011#L109-L123`):**
   * `checkout_intent` (`checkout_intent_id`, `customer_id`, `business_unit_id`, `intent_token UNIQUE`, `cart_version_hash`, `order_id NULL UNIQUE`, `intent_status`, `created_at`, `expires_at`).
   * `business_settings`: add online gateway availability columns isolated from legacy OTP columns.

---

## 14. Required Application Services, Controllers, Routes, Views & CLI Processes

* **Application Services (`app/Services/`):**
  * `PaymentGatewayInterface` & offline `NullPaymentGateway` *(concrete provider adapter added only after Provider Selection & Sandbox Verification)*.
  * `CheckoutService` (idempotent pending-order creation; reserve FEFO stock only in the store-confirmation transaction; payment-attempt sequencing remains gated by OD-04/OD-08).
  * `InventoryReservationService` (`reserveForOrder()`, `fulfillPickup()`, `dispatchToInTransit()`, `confirmDeliveryFromInTransit()`, `recordReturnPendingInspection()`, `completeReturnInspection()` supporting sellable/damaged/mixed splits, `cancelReservation()`, and POS `deductWalkInSale()`).
  * `OrderPaymentEligibilityService` (shared check requiring settled/COD status **and** `payment_conflict_status = 'none'`, valid active/`in_transit` reservation, and **zero** active/approved/settled full refund—never relying on residual net balance alone).
  * `PaymentWebhookService` (signature verification, Two-Phase durable inbox + lease deduplication, §7.2 settlement invariants, conflict escalation + out-of-band `MailService`).
  * `PaymentReservationExpiryService` (lease-based expiry only for confirmed orders with active reservations and an approved finite TTL; no unconfirmed-order or automatic COD expiry).
  * `PaymentRefundService` (`payment_refund` remaining-balance locks, gateway submission, timeout recovery, and `1:1` `'refund'` ledger posting).
  * `CodRemittanceService` (collection custody recording + authorized remittance verification).
* **Controllers, Routes & Views:**
  * [public/index.php](file:///d:/VETGRPETproj/public/index.php): Mount `/webhooks/payment-gateway` (`POST`), `/orders/{id}/pay` & `/orders/{id}/payment-return`, **`DeliveryController` routes (`/delivery/orders`, `/dispatch`, `/complete`, `/fail`)**, store return receipt / mixed inspection / post-return cancel routes, COD remittance verification route, and refund-execution route (`ISS-21`).
  * [AuthController.php](file:///d:/VETGRPETproj/app/Controllers/AuthController.php#L70-L118) & [layout.php](file:///d:/VETGRPETproj/app/Views/layout.php#L6-L250): Register `DELIVERY_PERSONNEL` landing, `/delivery` workspace nav link, and `app/Views/delivery/orders.php` (`ISS-21`).
  * [OrderController](file:///d:/VETGRPETproj/app/Controllers/OrderController.php), [CartController](file:///d:/VETGRPETproj/app/Controllers/CartController.php), [ShopController](file:///d:/VETGRPETproj/app/Controllers/ShopController.php): Enforce Unit `1` checkout eligibility if approved (`ISS-15`), `in_transit` `Available` formula, and payment/conflict display.
  * [PetStoreReceptionistController](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php), [ManagerController](file:///d:/VETGRPETproj/app/Controllers/ManagerController.php), [SuperAdminController](file:///d:/VETGRPETproj/app/Controllers/SuperAdminController.php): Fix POS races/batches (`ISS-01`, `ISS-07`), grouped `payment` joins (`ISS-05`), `$routeUnitId` settings (`ISS-14`), revenue/timezone KPIs (`ISS-22`), return inspection UI (`1.D`), and conflict banners (`ISS-23`).
* **Scheduled CLI Workers (`tools/php83/php.exe`, `ISS-17`):** Periodic sweeps for (a) reservation expiry verification (§8.1), (b) `'initiating_uncertain'` / `'uncertain_timeout'` reconciliation, and (c) persisted `'received'`, `'retry_needed'`, and lease-expired `'processing'` webhook inbox events (§7.1).

---

## 15. Preserved Issue Register (`ISS-01` through `ISS-23`) and Candidate Findings (`ISS-24` through `ISS-27`)

| ID | Issue Title | Classification | Evidence / Primary Reference |
|---|---|---|---|
| **ISS-01** | Walk-in POS TOCTOU stock race & unaggregated lines | **Confirmed defect** | [PetStoreReceptionistController::createSale()](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L404-L478) |
| **ISS-02** | Pickup status update lacks DB transaction & conditional status lock | **Confirmed defect** | [PetStoreReceptionistController::updatePickupStatus()](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L701-L741) |
| **ISS-03** | Pickup completion has no payment gate or counter collection | **Missing Phase 1 functionality** | [PetStoreReceptionistController::updatePickupStatus()](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L701-L741) |
| **ISS-04** | Online orders omit `payment`, `inventory_reservation`, and stock deduction | **Missing Phase 1 functionality** | [OrderController::place()](file:///d:/VETGRPETproj/app/Controllers/OrderController.php#L28-L115) |
| **ISS-05** | `LEFT JOIN payment` duplicates order/sale rows once >1 payment/refund row exists *(grouped join/aggregation required even within `'payment'` rows)* | **Confirmed defect** | [PetStoreReceptionistController.php#L170](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L170), [ManagerController.php#L2935](file:///d:/VETGRPETproj/app/Controllers/ManagerController.php#L2935) |
| **ISS-06** | Walk-in POS non-cash methods mark `'completed'` with no verification/ref ID | **Confirmed defect** *(deferred)* | [PetStoreReceptionistController::createSale()](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L481-L485) |
| **ISS-07** | POS ignores batches; `InventoryBatch::allocateFefo()` deducts stock prematurely & ignores `committed`/holds | **Confirmed defect** | [PetStoreReceptionistController.php#L460-L478](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L460-L478), [InventoryBatch.php#L100-L224](file:///d:/VETGRPETproj/app/Models/InventoryBatch.php#L100-L224) |
| **ISS-08** | Mixed UTC DB session (`+00:00`) vs PHP `Asia/Manila` & SQL `CURDATE()` | **Confirmed defect / Technical risk** | [config/database.php#L32](file:///d:/VETGRPETproj/config/database.php#L32), [InventoryBatch.php#L32](file:///d:/VETGRPETproj/app/Models/InventoryBatch.php#L32) |
| **ISS-09** | Skeleton `payment` table lacks gateway/idempotency columns & relational constraints | **Missing Phase 1 functionality** | [001_initial_schema.sql#L392-L409](file:///d:/VETGRPETproj/database/migrations/001_initial_schema.sql#L392-L409) |
| **ISS-10** | `refund_request` approval never executes refund or writes `payment` `'refund'` row | **Missing Phase 1 functionality** | [012_refund_request_schema.sql](file:///d:/VETGRPETproj/database/migrations/012_refund_request_schema.sql), [ManagerController.php#L3117-L3183](file:///d:/VETGRPETproj/app/Controllers/ManagerController.php#L3117-L3183) |
| **ISS-11** | Signed `INT` stock; unit-only `inventory`; Rule 6/8 return movement conflict | **Confirmed defect / Policy gap** | [001_initial_schema.sql#L161-L279](file:///d:/VETGRPETproj/database/migrations/001_initial_schema.sql#L161-L279), [BUSINESS_RULES.md#L167-L198](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L167-L198) |
| **ISS-12** | `money()`/`peso()` renders `'$'` instead of `'₱'` *(External KYC impact: Unverified)* | **Confirmed defect (UI/localization)** | [app/bootstrap.php#L23-L31](file:///d:/VETGRPETproj/app/bootstrap.php#L23-L31) |
| **ISS-13** | `config/database.php` parses `.env` directly via `parse_ini_file()` instead of `EnvConfig` | **Confirmed defect / Technical risk** | [config/database.php#L9](file:///d:/VETGRPETproj/config/database.php#L9) |
| **ISS-14** | `ManagerController::updatePaymentSettings()` ignores `$routeUnitId` | **Confirmed defect** | [ManagerController.php#L4910-L4953](file:///d:/VETGRPETproj/app/Controllers/ManagerController.php#L4910-L4953) |
| **ISS-15** | Shop/cart/checkout allow Units `2` & `3` while fulfillment workflows are Pet-Store-only | **Scope/policy question — not a confirmed defect until Phase 1 unit scope is approved** | [ShopController.php](file:///d:/VETGRPETproj/app/Controllers/ShopController.php), [CartController.php](file:///d:/VETGRPETproj/app/Controllers/CartController.php), [OrderController.php](file:///d:/VETGRPETproj/app/Controllers/OrderController.php) |
| **ISS-16** | Checkout double-POST & gateway timeout/lost-response recovery gap | **Confirmed technical risk** | [OrderController::place()](file:///d:/VETGRPETproj/app/Controllers/OrderController.php#L53-L99) |
| **ISS-17** | No background worker daemon; PHP CLI requires explicit binary path | **Confirmed technical risk** | [implementation_plan.md#L8-L9](file:///d:/VETGRPETproj/implementation_plan.md#L8-L9) |
| **ISS-18** | Failed-delivery return and inspection policy gap | **Core return/inspection requirement clarified by OD-09; retry cap, cancellation eligibility, movement implementation and custody roles remain open** | [DeliveryController.php#L551-L629](file:///d:/VETGRPETproj/app/Controllers/DeliveryController.php#L551-L629), [BUSINESS_RULES.md#L130-L141](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L130-L141) |
| **ISS-19** | Refund-related fulfillment eligibility (`evaluatePaymentEligibility()` blanket `'refund'` block vs net payment) | **Phase 1: blanket block on completed refund is not automatically a defect; review active/full-refund states; future partial-refund behavior deferred** | [DeliveryController.php#L163-L171](file:///d:/VETGRPETproj/app/Controllers/DeliveryController.php#L163-L171) |
| **ISS-20** | COD collection versus courier remittance policy gap | **Core collection/remittance lifecycle resolved by OD-10; verifier-role matrix and exception handling remain open** | [DeliveryController.php#L136-L146, #L484-L549](file:///d:/VETGRPETproj/app/Controllers/DeliveryController.php#L484-L549) |
| **ISS-21** | `DeliveryController` integration gap (routes, views, nav, landing/workspace integration absent despite controller existing) | **Confirmed defect / Missing wiring** | [public/index.php](file:///d:/VETGRPETproj/public/index.php), [AuthController.php#L70-L118](file:///d:/VETGRPETproj/app/Controllers/AuthController.php#L70-L118), [layout.php#L6-L250](file:///d:/VETGRPETproj/app/Views/layout.php#L6-L250) |
| **ISS-22** | Revenue-reporting and KPI inaccuracies (`order_revenue`, UTC `CURDATE()` POS sums) | **Confirmed defect** | [SuperAdminController.php#L44, #L111-L114](file:///d:/VETGRPETproj/app/Controllers/SuperAdminController.php#L111-L114), [PetStoreReceptionistController.php#L113](file:///d:/VETGRPETproj/app/Controllers/PetStoreReceptionistController.php#L113) |
| **ISS-23** | Late-payment conflict handling and notification gap | **Missing Phase 1 functionality / Policy gap** | [001_initial_schema.sql#L214-L234](file:///d:/VETGRPETproj/database/migrations/001_initial_schema.sql#L214-L234), `MailService` |

### 15.1 Candidate Findings Awaiting Explicit Acceptance (`ISS-24`–`ISS-27`)

These findings are retained as **candidates**, not silently promoted to confirmed defects. Preserve their IDs and evidence while the owner/reviewer confirms their acceptance:

| Candidate ID | Finding | Why it matters | Evidence / status |
|---|---|---|---|
| **ISS-24** | `DeliveryController::dispatch()` accepts `pending` orders as well as confirmed/processing orders | Bypasses the store-confirmation boundary; dispatch must require confirmed order and valid reservation | `DeliveryController.php#L407`; candidate pending confirmation |
| **ISS-25** | Payment eligibility/order listing reads only the latest `payment` row | A later failed retry can hide an earlier settled payment; duplicate settlements may be missed | `DeliveryController.php#L177-L185, #L337-L343`; candidate pending confirmation |
| **ISS-26** | Delivery dispatch/completion/failure does not perform reservation and `in_transit` custody transitions | Inventory quantities and order custody can diverge from courier reality | `DeliveryController.php` dispatch/complete/fail paths; candidate pending confirmation |
| **ISS-27** | COD courier collection and staff remittance verification are not implemented in the delivery workflow | OD-10 requires separate collection custody and authorized remittance verification | `DeliveryController.php` completion/remittance paths; candidate pending confirmation |

---

## 16. Phased Implementation Sequence & Acceptance Criteria `[Proposed Technical Design]`

1. **Gate 0 — Source Reconciliation & Owner Policy Closure:** Amend `BUSINESS_RULES.md`/ERD for OD-09 and resolve the Rule 6/8 conflict, then close the policy questions that block implementation (§17.2). Architecture may be baselined without selecting a provider, but unresolved items must remain explicitly out of scope.
2. **Gate 1 — Provider Selection & Sandbox Verification:** Select provider, verify session TTL, idempotency, webhook cryptography/retry/payload schema, refund API constraints, and onboarding KYC (`§17.4`) against sandbox tests.
3. **Gate 2 — Foundation & Data Safety Migration (`020+` + Defect Fixes):** Apply schema migrations (`in_transit`, `inventory_reservation` split inspection counts, `checkout_intent`, `payment_attempt` + `payment_attempt_id` FK, `payment_refund` + `payment_refund_id` FK, `payment_webhook_event` lease/retry states, COD remittance columns) and fix `ISS-01`, `ISS-02`, `ISS-05`, `ISS-07`, `ISS-08`, `ISS-12`, `ISS-13`, `ISS-14`, `ISS-22`.
4. **Gate 3 — Pickup, Delivery (`OD-09`, `OD-10`) & Checkout Integration:** Wire `DeliveryController` (`ISS-21`, candidate `ISS-24`–`ISS-27`), deploy `CheckoutService`, `InventoryReservationService`, `OrderPaymentEligibilityService`, `CodRemittanceService`, pickup payment/handover deduction, and mixed failed-delivery inspection.
5. **Gate 4 — Provider Adapter, Durable Webhooks, Lock-Free Expiry & Refunds:** Implement provider adapter behind `PaymentGatewayInterface`, Two-Phase webhook inbox (§7.1–§7.2), 6-step lease-based expiry CLI (§8.1), `paid_after_expiry`/`duplicate_payment` escalation (§8.2), and two-step `PaymentRefundService` (`ISS-10`, `OD-11`).

---

## 17. Policy Authority Reconciliation & Open Questions

### 17.1 Rules Resolved by `BUSINESS_RULES.md` & Confirmed Decisions (`OD-09`–`OD-11`) — *Do Not Re-Ask Owner*

| Policy Area | Established Rule | Authoritative Source |
|---|---|---|
| **Separate Checkouts per Business Unit** | Three business units supported; catalog browsing per unit; separate checkout/transactions per BU | [BUSINESS_RULES.md#L15-L19, #L90-L92, #L224-L226](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L15-L19) |
| **Dual Customer / Employee Account Identity** | Customer and employee concepts are separate; a single user account may link to both as appropriate | [BUSINESS_RULES.md#L27-L29](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L27-L29) |
| **No Reservation Before Confirmation** | Cart contents do not reserve stock; an unconfirmed pending order does not reserve stock; confirmation creates the reservation and increases `committed` | [BUSINESS_RULES.md §5.1, §6.2] |
| **Pickup Stock Deduction Timing** | Confirmation/ready states commit stock; physical `on_hand` deduction & `committed` release occur at actual pickup handover | [BUSINESS_RULES.md#L134-L136, #L230-L232](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L134-L136) |
| **Delivery `in_transit` Lifecycle (`OD-09`)** | OD-09 requires dispatch to reduce `on_hand`, increase `in_transit`, and retain `committed` until delivery confirmation or inspected return. This supersedes older `BUSINESS_RULES.md#L137-L138`; amend that document and ERD before implementation | **OD-09** plus current `BUSINESS_RULES.md#L137-L138` conflict |
| **Return Receipt & Mandatory Inspection** | Failed deliveries/returns do not auto-restock; physical return and inspection precede sellable restock. Exact Rule 6/8 movement rows remain unresolved/not approved | [BUSINESS_RULES.md#L140-L141, #L194-L198, #L272-L281] + **OD-09** |
| **COD Collection vs Remittance (`OD-10`)** | Courier collection is recorded as `collected_pending_remittance` while payment remains `pending`; a different authorized staff member verifies remittance and then payment becomes `completed`. OD-10 does not decide the verifier-role matrix or COD availability | **OD-10** *(Confirmed Owner Clarification)* |
| **Partial Refunds Excluded (`OD-11`) & Preservation** | Pre-fulfillment partial refunds excluded in Phase 1; full refund requests follow existing `refund_request` workflow; original payment rows preserved and distinct from physical returns | [BUSINESS_RULES.md#L258-L264, #L279-L281](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L258-L264) + **OD-11** |
| **Unit-Level Inventory & Batch FEFO** | `inventory` is `1:1` with `business_product` (unit-scoped); batch-tracked products use FEFO across eligible non-expired batches | [BUSINESS_RULES.md#L124-L125, #L148-L163](file:///d:/VETGRPETproj/database/BUSINESS_RULES.md#L148-L163) |

### 17.2 Unresolved Business-Owner Policy Decisions Awaiting Approval (`[Recommendation — Awaiting Approval]`)
1. **Units `2` & `3` Phase 1 Ordering Restriction (`OD-13A`, `ISS-15`):** Business rules confirm separate unit checkouts where supported, not a Pet-Store-only Phase 1 restriction. Obtain owner sign-off before disabling Units 2 and 3 checkout.
2. **Online Payment Channels & OTC Deferral (`OD-02`, `OD-03`):** Select supported digital rails and decide whether OTC payment centers are deferred.
3. **Gateway Fee Absorption (`OD-07`) & Delivery Fee (`OD-13C`):** Confirm MDR fee treatment and whether Phase 1 delivery fee is `₱0.00`.
4. **Payment-Before-Confirmation and Pickup Prepayment (`OD-04`, `OD-08`):** `BUSINESS_RULES.md §6.2` explicitly leaves payment-before-confirmation unresolved. Decide whether online payment may occur before store acceptance, whether pickup requires prepayment, and the exact order/payment transition sequence. Until decided, the spec must not treat automated payment confirmation as order confirmation.
5. **Reservation Hold TTL and COD Reservation/Expiry:** Decide when the finite reservation clock begins after store confirmation, the maximum provider async-payment extension, and the absolute hold ceiling. COD has no gateway session; decide whether confirmed COD reservations have a timeout and what cancellation path applies. Do not create indefinite reservations by setting expiry NULL by default.
6. **Late-Payment Conflict & Order Reinstatement (`OD-06`, `ISS-23`):** Decide whether late-paid cancelled orders remain cancelled and whether a pending full-refund request should be created automatically. Ledgering verified incoming funds and preventing automatic stock re-lock are technical safeguards; automatic refund-request creation is not yet approved.
7. **Failed-Delivery Retry Cap, Cancellation Eligibility & Custody Roles (`OD-12A–D`, `ISS-18`):** Set maximum retries, post-failure cancellation/refund eligibility, and who may receive returns, inspect stock, authorize cancellation, and re-dispatch.
8. **COD Remittance Verifier Role Matrix (`OD-10` follow-up, `ISS-20/27`):** Identify eligible staff roles; verifier must not be the collecting courier. Define cash short/over-collection, missing cash, and disputed remittance handling.
9. **Rule 6/Rule 8 Inventory Movement Amendment:** `BUSINESS_RULES.md` explicitly marks this conflict **NOT APPROVED**. Owner must approve a corrected movement taxonomy and accounting treatment for monetary refunds versus physical returns, including damaged/lost units, before implementation. No zero-delta `return_damaged` movement is approved by this spec.
10. **Refund Phase 1 Eligibility (`OD-11`, `ISS-19`):** Confirm eligible full-refund scenarios, especially paid-after-expiry and duplicate-payment conflicts. Pre-fulfillment partial refunds remain excluded in Phase 1; broader ERD intent for partial/multiple refunds is a future capability, not a Phase 1 authorization.
11. **Business Rules / ERD Amendment for OD-09:** Approve a source-document amendment replacing the old dispatch release wording and updating the availability formula to account for `in_transit` while `committed` remains held.

### 17.3 Proposed Technical Recommendations (`[Proposed Technical Design]` — *Not Approved Owner Decisions*)
* **Checkout Intent + Phase A/B Split & Attempt Locking (`§4`, `§5.2`):** Atomic pre-call `checkout_intent` commit + explicit `payment.payment_attempt_id UNIQUE` mapping + `'initiating_uncertain'` blocking.
* **Two-Phase Durable Webhook Inbox (`§7.1`) & 6-Step Settlement Gate (`§7.2`):** Persist-before-ack, `processing_status` inspection on retries, `processing_lease_until` concurrency control, CLI retry recovery, and strict merchant/attempt/amount/currency/status/mode checks.
* **6-Step Lock-Free HTTP Expiry Worker (`§8.1`):** Short DB claim lease committed before provider HTTP lookup, followed by fresh `FOR UPDATE` state re-verification guarding progressed orders, retries, and concurrent webhooks.
* **Two-Step Decoupled Refund Execution (`§9`, `OD-05`):** Executing outbound provider refund APIs asynchronously from a separate authorized click (`payment_refund` + `UNIQUE(payment.payment_refund_id)`) rather than blocking Manager approval on synchronous HTTP calls.
* **Mixed-Inspection Data Capture (`sellable_quantity`, `damaged_quantity`) (`§6.2`):** Proposed reservation-level inspection quantities and audit records. Final movement rows/types, including whether any zero-delta row is appropriate, are deferred until Rule 6/8 is approved.
* **Technical Defect & Security Hardening:** Grouped `payment` subqueries (`ISS-05`), `EnvConfig` consolidation (`ISS-13`), self-order employee separation guard (`§11`), Manila-to-UTC query boundaries (`ISS-08`, `ISS-22`), and review of candidate `ISS-24`–`ISS-27` before acceptance.

### 17.4 Provider-Dependent Assumptions Requiring Sandbox Verification (`[Provider-Dependent — Unverified]`)
* **Idempotency & Inquiry APIs:** Session/refund idempotency key headers, retention windows, explicit session `expire` endpoint support, and canonical `GET` retrieval payload fields.
* **Checkout Session TTL & Async E-Wallet Latency:** Minimum/maximum expiry bounds and settlement lag for QR/e-wallet rails.
* **Webhook Cryptography & Refund Events:** Header signature scheme (`HMAC`/`JWS`/token + IP allowlist), retry timestamp tolerance, and refund webhook event coverage.
* **Onboarding KYC & Commercial Terms:** Exact MDR/VAT billing, settlement `T+N` cadence, minimum refund amounts, and whether merchant KYC requires `ISS-12` (`₱`) checkout UI updates.
