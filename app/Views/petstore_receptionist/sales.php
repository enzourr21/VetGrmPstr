<?php
$title = $title ?? 'Sales · Pet Store Receptionist';
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Pet Store Workspace</p>
    <h1>Sales</h1>
    <p class="muted">Record point-of-sale customer transactions, oversee daily store sales, and monitor pending order fulfillments.</p>
  </div>
</div>

<!-- Daily Performance Metrics & Daily Sales Orders Breakdown -->
<div class="grid grid-2" style="margin-bottom: 1.5rem; gap: 1.25rem; align-items: stretch;">
  <!-- Card A: Daily Total Sales -->
  <div class="card stat-card" style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; justify-content: space-between; height: 100%; box-sizing: border-box;">
    <div>
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
        <span class="caption muted" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Daily Total Sales</span>
        <span style="font-size: 1.25rem;" aria-hidden="true">💰</span>
      </div>
      <div style="font-size: 2rem; font-weight: 700; color: var(--color-text, #111827); line-height: 1.2; margin-top: 0.5rem;">
        ₱<?= number_format($dailyTotalSales, 2) ?>
      </div>
    </div>
    <p class="caption muted" style="margin: 0.65rem 0 0 0;">
      Total value of successful sales completed today (<?= date('M j, Y') ?>). Excludes voided or unpaid transactions.
    </p>
  </div>

  <!-- Card B: Daily Sales Orders Breakdown -->
  <div class="card stat-card" style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; justify-content: space-between; height: 100%; box-sizing: border-box;">
    <div>
      <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.5rem;">
        <span class="caption muted" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;">Daily Sales</span>
        <span style="font-size: 1.25rem;" aria-hidden="true">📦</span>
      </div>
      <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; align-items: center; margin-top: 0.5rem;">
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 0.65rem 0.75rem; border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb); text-align: center;">
          <div style="font-size: 1.5rem; font-weight: 700; color: #d97706; line-height: 1.2;">
            <?= (int)$dailyPendingOrders ?>
          </div>
          <div class="caption muted" style="font-size: 0.75rem; font-weight: 600; margin-top: 0.2rem; text-transform: uppercase;">Pending</div>
        </div>
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 0.65rem 0.75rem; border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb); text-align: center;">
          <div style="font-size: 1.5rem; font-weight: 700; color: #0284c7; line-height: 1.2;">
            <?= (int)$dailyReadyPickupOrders ?>
          </div>
          <div class="caption muted" style="font-size: 0.75rem; font-weight: 600; margin-top: 0.2rem; text-transform: uppercase;">Ready for Pickup</div>
        </div>
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 0.65rem 0.75rem; border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb); text-align: center;">
          <div style="font-size: 1.5rem; font-weight: 700; color: #059669; line-height: 1.2;">
            <?= (int)$dailyOutDeliveryOrders ?>
          </div>
          <div class="caption muted" style="font-size: 0.75rem; font-weight: 600; margin-top: 0.2rem; text-transform: uppercase;">Out for Delivery</div>
        </div>
      </div>
    </div>
    <p class="caption muted" style="margin: 0.65rem 0 0 0;">
      Today's orders breakdown: pending, ready for pickup, and out for delivery.
    </p>
  </div>
</div>

<!-- Card C: Recent Sales Card with In-Card Pagination -->
<section class="card" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;">
        Recent Sales Transactions
      </h2>
      <p class="caption muted" style="margin: 0.25rem 0 0 0;">
        Displaying recent confirmed sales transactions sorted newest first.
      </p>
    </div>
    <div style="display: flex; gap: 0.5rem; align-items: center;">
      <button type="button" class="btn primary btn-sm" data-modal-open="new-sale-modal">
        New Sale
      </button>
    </div>
  </div>

  <?php if (empty($recentSales)): ?>
    <div class="empty" style="padding: 2.5rem 1rem; text-align: center;">
      <p style="margin: 0; font-size: 1rem; color: var(--color-muted, #6b7280);">
        No successful sales transactions recorded yet.
      </p>
      <button type="button" class="btn primary btn-sm" data-modal-open="new-sale-modal" style="margin-top: 1rem;">
        New Sale
      </button>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left;">
            <th style="padding: 0.75rem 1rem; font-size: 0.825rem; font-weight: 600; text-transform: uppercase;">Sale ID</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.825rem; font-weight: 600; text-transform: uppercase;">Customer</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.825rem; font-weight: 600; text-transform: uppercase;">Date &amp; Time</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.825rem; font-weight: 600; text-transform: uppercase;">Items</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.825rem; font-weight: 600; text-transform: uppercase;">Payment</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.825rem; font-weight: 600; text-transform: uppercase; text-align: right;">Total Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentSales as $s): ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
              <td style="padding: 0.875rem 1rem; font-weight: 600; font-family: var(--font-mono, monospace);">
                #SALE-<?= (int)$s['sale_id'] ?>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <div style="font-weight: 500; color: var(--color-text, #111827);">
                  <?= e($s['customer_name']) ?>
                </div>
                <?php if (!empty($s['customer_id'])): ?>
                  <span class="caption muted">ID #<?= (int)$s['customer_id'] ?></span>
                <?php else: ?>
                  <span class="caption muted" style="font-style: italic;">Walk-in</span>
                <?php endif; ?>
              </td>
              <td style="padding: 0.875rem 1rem; white-space: nowrap;">
                <div><?= date('M j, Y', strtotime((string)$s['sale_date'])) ?></div>
                <span class="caption muted"><?= date('g:i A', strtotime((string)$s['sale_date'])) ?></span>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <span class="badge" style="background: var(--color-bg-subtle, #f3f4f6); color: var(--color-text, #374151);">
                  <?= (int)($s['item_count'] ?? count($s['items'] ?? [])) ?> item(s)
                </span>
                <?php if (!empty($s['items'])): ?>
                  <div class="caption muted" style="margin-top: 0.25rem; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    <?= e(implode(', ', array_map(fn($it) => $it['quantity'] . '× ' . $it['product_name'], $s['items']))) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <span class="badge badge-success">Completed</span>
                <div class="caption muted" style="margin-top: 0.2rem;"><?= e($s['payment_method'] ?? 'Cash') ?></div>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: right; font-weight: 700; color: var(--color-text, #111827); font-size: 1.05rem;">
                ₱<?= number_format((float)$s['total_amount'], 2) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Direct In-Card Pagination Controls -->
    <?php if (!empty($totalSalesCount) && $totalSalesCount > 0): ?>
      <div class="pagination-bar" style="display: flex; justify-content: space-between; align-items: center; padding: 0.85rem 0.25rem 0.25rem; border-top: 1px solid var(--color-border, #e5e7eb); margin-top: 0.75rem; flex-wrap: wrap; gap: 0.75rem;">
        <span class="caption muted">
          Page <?= (int)$currentPage ?> of <?= (int)$totalPages ?> (<?= (int)$totalSalesCount ?> total sales)
        </span>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <?php if ($currentPage > 1): ?>
            <a href="/petstore/receptionist/sales?page=<?= (int)($currentPage - 1) ?>" class="btn secondary btn-sm" id="sales-prev-btn">&lsaquo; Previous</a>
          <?php else: ?>
            <button type="button" class="btn secondary btn-sm is-disabled" id="sales-prev-btn" disabled aria-disabled="true" style="opacity: 0.5; cursor: not-allowed;">&lsaquo; Previous</button>
          <?php endif; ?>

          <?php if ($currentPage < $totalPages): ?>
            <a href="/petstore/receptionist/sales?page=<?= (int)($currentPage + 1) ?>" class="btn secondary btn-sm" id="sales-next-btn">Next &rsaquo;</a>
          <?php else: ?>
            <button type="button" class="btn secondary btn-sm is-disabled" id="sales-next-btn" disabled aria-disabled="true" style="opacity: 0.5; cursor: not-allowed;">Next &rsaquo;</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<!-- ========================================== -->
<!-- D. NEW SALE MODAL                          -->
<!-- ========================================== -->
<dialog class="modal modal-wide" id="new-sale-modal" aria-labelledby="new-sale-title">
  <div class="modal-head">
    <h3 id="new-sale-title" style="margin: 0; font-size: 1.25rem;">New Sale</h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
  </div>
  <div class="modal-body" style="padding: 1.5rem;">
    <form id="new-sale-form" method="post" action="/petstore/receptionist/sales">
      <?= \App\Core\CSRF::field() ?>

      <!-- Section: Customer Information -->
      <fieldset style="border: 1px solid var(--color-border, #e5e7eb); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
        <legend style="padding: 0 0.5rem; font-weight: 600; font-size: 0.95rem; color: var(--color-primary, #047857);">
          Customer Information
        </legend>

        <!-- Customer Type Selection: Radio Controls -->
        <div style="margin-bottom: 1.25rem;">
          <span class="caption" style="font-weight: 600; display: block; margin-bottom: 0.4rem;">Select Customer Type</span>
          <div style="display: flex; gap: 1.5rem; align-items: center;">
            <label style="display: flex; align-items: center; gap: 0.45rem; cursor: pointer; font-size: 0.95rem;">
              <input type="radio" name="customer_type" value="walkin" checked id="type-walkin">
              <span><strong>Walk-in Customer</strong></span>
            </label>
            <label style="display: flex; align-items: center; gap: 0.45rem; cursor: pointer; font-size: 0.95rem;">
              <input type="radio" name="customer_type" value="registered" id="type-registered">
              <span><strong>Registered Customer</strong></span>
            </label>
          </div>
        </div>

        <!-- Registered Customer Search Input (Visible only when Registered Customer is selected) -->
        <div id="registered-search-wrap" style="display: none; position: relative; margin-bottom: 1rem;">
          <label for="registered-customer-search-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
            Search Registered Customer
          </label>
          <div style="position: relative;">
            <input type="search" id="registered-customer-search-input" placeholder="Type customer name, email, or phone..." autocomplete="off" class="search-input" style="width: 100%;">
            <div id="customer-search-spinner" style="display: none; position: absolute; right: 12px; top: 10px; font-size: 0.85rem;" class="muted">
              Searching...
            </div>
          </div>
          <p class="caption muted" style="margin: 0.25rem 0 0 0;">
            Search existing registered customers. Select a result to populate customer details.
          </p>
          <!-- Live Suggestions Dropdown: EXACT FORMAT `Customer Name #CustomerID` -->
          <ul id="customer-suggestions" class="card" style="display: none; position: absolute; z-index: 100; left: 0; right: 0; top: 100%; margin-top: 4px; padding: 0.25rem 0; list-style: none; max-height: 200px; overflow-y: auto; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid var(--color-border, #e5e7eb); background: var(--color-surface, #ffffff);">
          </ul>
        </div>

        <!-- Customer Fields Row -->
        <div class="grid grid-2" style="gap: 1rem;">
          <div class="field" style="margin: 0;">
            <label for="sale-customer-name" id="sale-customer-name-label" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
              Customer Name <span class="muted" style="font-weight: 400;">(Optional)</span>
            </label>
            <input type="text" id="sale-customer-name" name="customer_name" placeholder="Walk-in Customer" autocomplete="off" style="width: 100%;">
          </div>

          <div class="field" style="margin: 0;">
            <label for="sale-customer-id" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
              Customer ID <span class="muted" style="font-weight: 400;">(Read-only, populated via search)</span>
            </label>
            <input type="text" id="sale-customer-id" name="customer_id" placeholder="None (Walk-in)" readonly style="width: 100%; background: var(--color-bg-subtle, #f9fafb); cursor: not-allowed;">
          </div>
        </div>

        <div id="registered-cust-warning" class="caption" style="color: #dc2626; margin-top: 0.5rem; font-weight: 600; display: none;">
          Please search and select a valid registered customer before confirming the sale.
        </div>
      </fieldset>

      <!-- Section: Sale Items -->
      <fieldset style="border: 1px solid var(--color-border, #e5e7eb); border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
        <legend style="padding: 0 0.5rem; font-weight: 600; font-size: 0.95rem; color: var(--color-primary, #047857);">
          Sale Items
        </legend>

        <!-- Searchable Product Selection Input (No full catalog native dropdown) -->
        <div style="display: flex; gap: 0.75rem; align-items: flex-end; margin-bottom: 1rem; flex-wrap: wrap;">
          <div style="position: relative; flex: 2; min-width: 280px;" id="product-search-wrap">
            <label for="product-search-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">
              Search Pet Store Product
            </label>
            <div style="position: relative;">
              <input type="search" id="product-search-input" placeholder="Type product name, category, or SKU..." autocomplete="off" class="search-input" style="width: 100%;">
              <div id="product-search-spinner" style="display: none; position: absolute; right: 12px; top: 10px; font-size: 0.85rem;" class="muted">
                Searching...
              </div>
            </div>
            <!-- Product Autocomplete Suggestions Overlay -->
            <ul id="product-suggestions" class="card" style="display: none; position: absolute; z-index: 100; left: 0; right: 0; top: 100%; margin-top: 4px; padding: 0.25rem 0; list-style: none; max-height: 220px; overflow-y: auto; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid var(--color-border, #e5e7eb); background: #ffffff;">
            </ul>
          </div>

          <div style="width: 110px;">
            <label for="product-add-qty" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Quantity</label>
            <input type="number" id="product-add-qty" min="1" value="1" style="width: 100%;">
          </div>

          <div>
            <button type="button" class="btn primary" id="add-item-btn" style="height: 38px;">
              + Add Item
            </button>
          </div>
        </div>

        <!-- Selected Product Feedback Banner -->
        <div id="selected-product-preview" style="display: none; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; padding: 0.5rem 0.85rem; margin-bottom: 1rem; font-size: 0.88rem; justify-content: space-between; align-items: center;">
          <div>
            <span style="font-weight: 700; color: #065f46;" id="preview-prod-name">Product Name</span>
            <span class="caption muted" id="preview-prod-meta" style="margin-left: 0.5rem;">₱0.00 · Available Stock: 0</span>
          </div>
          <button type="button" class="btn ghost btn-sm" id="clear-selected-prod-btn" style="padding: 0.15rem 0.4rem; font-size: 0.8rem;">Clear</button>
        </div>

        <div id="product-picker-alert" class="alert error" style="display: none; margin-bottom: 1rem; padding: 0.5rem 0.75rem; font-size: 0.85rem;"></div>

        <!-- Line Items Table -->
        <div style="border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; overflow: hidden; margin-bottom: 1rem;">
          <table id="sale-items-table" style="width: 100%; border-collapse: collapse;">
            <thead>
              <tr style="background: var(--color-bg-subtle, #f9fafb); border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left;">
                <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600;">Product</th>
                <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: right;">Unit Price</th>
                <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: center; width: 100px;">Qty</th>
                <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: right;">Line Total</th>
                <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: center; width: 60px;">Action</th>
              </tr>
            </thead>
            <tbody id="sale-items-tbody">
              <tr id="empty-items-row">
                <td colspan="5" style="padding: 2rem; text-align: center; color: var(--color-muted, #9ca3af); font-size: 0.9rem;">
                  No products added yet. Search and select products above to add to this sale.
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr style="background: var(--color-bg-subtle, #f9fafb); border-top: 2px solid var(--color-border, #e5e7eb);">
                <th colspan="3" style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; font-size: 1rem;">
                  Overall Total:
                </th>
                <th id="sale-overall-total" style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; font-size: 1.2rem; color: var(--color-primary, #047857);">
                  ₱0.00
                </th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      </fieldset>

      <!-- Section: Payment & Confirmation -->
      <div class="row" style="justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 0.75rem;">
          <label for="payment-method-select" class="caption" style="font-weight: 600; margin: 0;">Payment Method:</label>
          <select id="payment-method-select" name="payment_method" style="min-width: 150px;">
            <option value="Cash" selected>Cash</option>
            <option value="Credit Card">Credit Card</option>
            <option value="Debit Card">Debit Card</option>
            <option value="GCash">GCash</option>
            <option value="Maya">Maya</option>
          </select>
        </div>

        <div style="display: flex; gap: 0.75rem;">
          <button type="button" class="btn ghost" data-modal-close>Cancel</button>
          <button type="submit" id="confirm-sale-submit-btn" class="btn primary" disabled>
            Confirm &amp; Complete Sale
          </button>
        </div>
      </div>
    </form>
  </div>
</dialog>

<!-- Client Script for New Sale Modal Interactivity -->
<script>
(function() {
  var typeWalkinRadio = document.getElementById('type-walkin');
  var typeRegisteredRadio = document.getElementById('type-registered');
  var regSearchWrap = document.getElementById('registered-search-wrap');
  var regSearchInput = document.getElementById('registered-customer-search-input');
  var customerSuggestions = document.getElementById('customer-suggestions');
  var customerSearchSpinner = document.getElementById('customer-search-spinner');
  var saleCustomerName = document.getElementById('sale-customer-name');
  var saleCustomerNameLabel = document.getElementById('sale-customer-name-label');
  var saleCustomerId = document.getElementById('sale-customer-id');
  var regWarning = document.getElementById('registered-cust-warning');

  var productSearchInput = document.getElementById('product-search-input');
  var productSuggestions = document.getElementById('product-suggestions');
  var productSearchSpinner = document.getElementById('product-search-spinner');
  var productAddQty = document.getElementById('product-add-qty');
  var addItemBtn = document.getElementById('add-item-btn');
  var pickerAlert = document.getElementById('product-picker-alert');
  var previewBox = document.getElementById('selected-product-preview');
  var previewName = document.getElementById('preview-prod-name');
  var previewMeta = document.getElementById('preview-prod-meta');
  var clearPreviewBtn = document.getElementById('clear-selected-prod-btn');

  var itemsTbody = document.getElementById('sale-items-tbody');
  var emptyRow = document.getElementById('empty-items-row');
  var overallTotalEl = document.getElementById('sale-overall-total');
  var confirmBtn = document.getElementById('confirm-sale-submit-btn');
  var saleForm = document.getElementById('new-sale-form');

  var activeProductSelection = null;
  var lineItems = [];
  var custSearchTimeout = null;
  var prodSearchTimeout = null;

  // Local products cache from server data
  var catalogProducts = <?= json_encode(array_map(function($p) {
    return [
      'business_product_id' => (int)$p['business_product_id'],
      'product_name' => (string)$p['product_name'],
      'product_category' => (string)($p['product_category'] ?? ''),
      'sku' => (string)($p['sku'] ?? ''),
      'selling_price' => (float)$p['selling_price'],
      'available_stock' => max(0, (int)$p['available_stock']),
      'in_stock' => ((int)$p['available_stock']) > 0,
    ];
  }, $availableProducts ?? []), JSON_UNESCAPED_UNICODE) ?>;

  // ========================================================
  // 1. CUSTOMER TYPE TOGGLE (Walk-in vs Registered)
  // ========================================================
  function updateCustomerTypeState() {
    var isRegistered = typeRegisteredRadio && typeRegisteredRadio.checked;

    // Reset customer selections on switch (prevents cross-contamination)
    if (saleCustomerId) saleCustomerId.value = '';
    if (saleCustomerName) saleCustomerName.value = '';
    if (regSearchInput) regSearchInput.value = '';
    if (customerSuggestions) {
      customerSuggestions.style.display = 'none';
      customerSuggestions.innerHTML = '';
    }

    if (isRegistered) {
      if (regSearchWrap) regSearchWrap.style.display = 'block';
      if (saleCustomerNameLabel) {
        saleCustomerNameLabel.innerHTML = 'Customer Name <span style="color: #dc2626;">*</span>';
      }
      if (saleCustomerName) {
        saleCustomerName.placeholder = 'Select via registered search';
        saleCustomerName.readOnly = true;
      }
      if (regWarning) regWarning.style.display = 'block';
      if (regSearchInput) regSearchInput.focus();
    } else {
      if (regSearchWrap) regSearchWrap.style.display = 'none';
      if (saleCustomerNameLabel) {
        saleCustomerNameLabel.innerHTML = 'Customer Name <span class="muted" style="font-weight: 400;">(Optional)</span>';
      }
      if (saleCustomerName) {
        saleCustomerName.placeholder = 'Walk-in Customer';
        saleCustomerName.readOnly = false;
      }
      if (regWarning) regWarning.style.display = 'none';
    }

    updateConfirmButtonState();
  }

  if (typeWalkinRadio) typeWalkinRadio.addEventListener('change', updateCustomerTypeState);
  if (typeRegisteredRadio) typeRegisteredRadio.addEventListener('change', updateCustomerTypeState);

  // Manual typing in walk-in mode ensures customer_id remains strictly empty
  if (saleCustomerName) {
    saleCustomerName.addEventListener('input', function() {
      if (typeWalkinRadio && typeWalkinRadio.checked) {
        saleCustomerId.value = '';
      }
    });
  }

  // ========================================================
  // 2. REGISTERED CUSTOMER SEARCH (`Customer Name #CustomerID`)
  // ========================================================
  if (regSearchInput && customerSuggestions) {
    regSearchInput.addEventListener('input', function() {
      var q = this.value.trim();
      clearTimeout(custSearchTimeout);

      if (q.length < 1) {
        customerSuggestions.style.display = 'none';
        customerSuggestions.innerHTML = '';
        if (customerSearchSpinner) customerSearchSpinner.style.display = 'none';
        return;
      }

      if (customerSearchSpinner) customerSearchSpinner.style.display = 'inline-block';

      custSearchTimeout = setTimeout(function() {
        fetch('/petstore/receptionist/customers/search?q=' + encodeURIComponent(q), {
          headers: { 'Accept': 'application/json' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
          if (customerSearchSpinner) customerSearchSpinner.style.display = 'none';
          customerSuggestions.innerHTML = '';

          if (!data.ok || !data.customers || data.customers.length === 0) {
            var li = document.createElement('li');
            li.style.padding = '0.6rem 0.85rem';
            li.style.fontSize = '0.85rem';
            li.className = 'muted';
            li.textContent = 'No registered customers found matching "' + q + '".';
            customerSuggestions.appendChild(li);
            customerSuggestions.style.display = 'block';
            return;
          }

          data.customers.forEach(function(c) {
            var li = document.createElement('li');
            li.style.padding = '0.6rem 0.85rem';
            li.style.cursor = 'pointer';
            li.style.fontSize = '0.9rem';
            li.style.borderBottom = '1px solid var(--color-border, #f3f4f6)';
            // Mandatory exact suggestion format: `Customer Name #CustomerID`
            li.innerHTML = '<strong>' + escapeHtml(c.label) + '</strong>' +
              (c.phone ? ' <span class="caption muted">(' + escapeHtml(c.phone) + ')</span>' : '');

            li.addEventListener('mouseenter', function() {
              this.style.background = 'var(--color-bg-subtle, #f3f4f6)';
            });
            li.addEventListener('mouseleave', function() {
              this.style.background = 'transparent';
            });

            li.addEventListener('click', function() {
              saleCustomerName.value = c.customer_name;
              saleCustomerId.value = String(c.customer_id);
              regSearchInput.value = c.label;
              customerSuggestions.style.display = 'none';
              if (regWarning) regWarning.style.display = 'none';
              updateConfirmButtonState();
            });

            customerSuggestions.appendChild(li);
          });
          customerSuggestions.style.display = 'block';
        })
        .catch(function() {
          if (customerSearchSpinner) customerSearchSpinner.style.display = 'none';
          customerSuggestions.style.display = 'none';
        });
      }, 220);
    });

    document.addEventListener('click', function(e) {
      if (!regSearchInput.contains(e.target) && !customerSuggestions.contains(e.target)) {
        customerSuggestions.style.display = 'none';
      }
    });
  }

  // ========================================================
  // 3. SEARCHABLE PRODUCT SELECTION (Autocomplete Overlay)
  // ========================================================
  function renderProductSuggestions(products) {
    if (!productSuggestions) return;
    productSuggestions.innerHTML = '';

    if (!products || products.length === 0) {
      var li = document.createElement('li');
      li.style.padding = '0.6rem 0.85rem';
      li.style.fontSize = '0.85rem';
      li.className = 'muted';
      li.textContent = 'No matching Pet Store products found.';
      productSuggestions.appendChild(li);
      productSuggestions.style.display = 'block';
      return;
    }

    products.forEach(function(p) {
      var li = document.createElement('li');
      li.style.padding = '0.6rem 0.85rem';
      li.style.borderBottom = '1px solid var(--color-border, #f3f4f6)';
      li.style.display = 'flex';
      li.style.justifyContent = 'space-between';
      li.style.alignItems = 'center';

      var isAvailable = p.in_stock && p.available_stock > 0;
      if (isAvailable) {
        li.style.cursor = 'pointer';
        li.addEventListener('mouseenter', function() {
          this.style.background = 'var(--color-bg-subtle, #f3f4f6)';
        });
        li.addEventListener('mouseleave', function() {
          this.style.background = 'transparent';
        });
        li.addEventListener('click', function() {
          selectProductForSale(p);
          productSuggestions.style.display = 'none';
        });
      } else {
        li.style.opacity = '0.55';
        li.style.cursor = 'not-allowed';
      }

      var leftDiv = document.createElement('div');
      leftDiv.innerHTML = '<div style="font-weight: 600; color: #111827;">' + escapeHtml(p.product_name) + '</div>' +
        '<div class="caption muted">' + (p.product_category ? escapeHtml(p.product_category) + ' • ' : '') + '₱' + p.selling_price.toFixed(2) + '</div>';

      var rightDiv = document.createElement('div');
      rightDiv.style.textAlign = 'right';
      if (isAvailable) {
        rightDiv.innerHTML = '<span class="badge" style="background: #d1fae5; color: #065f46; font-size: 0.75rem;">' + p.available_stock + ' in stock</span>';
      } else {
        rightDiv.innerHTML = '<span class="badge" style="background: #fee2e2; color: #991b1b; font-size: 0.75rem;">Out of stock</span>';
      }

      li.appendChild(leftDiv);
      li.appendChild(rightDiv);
      productSuggestions.appendChild(li);
    });

    productSuggestions.style.display = 'block';
  }

  function selectProductForSale(p) {
    activeProductSelection = p;
    if (previewBox && previewName && previewMeta) {
      previewName.textContent = p.product_name;
      previewMeta.textContent = '₱' + p.selling_price.toFixed(2) + ' · Available Stock: ' + p.available_stock;
      previewBox.style.display = 'flex';
    }
    if (productSearchInput) {
      productSearchInput.value = p.product_name;
    }
    if (pickerAlert) pickerAlert.style.display = 'none';
    if (productAddQty) productAddQty.focus();
  }

  function clearProductSelection() {
    activeProductSelection = null;
    if (previewBox) previewBox.style.display = 'none';
    if (productSearchInput) productSearchInput.value = '';
    if (pickerAlert) pickerAlert.style.display = 'none';
  }

  if (clearPreviewBtn) clearPreviewBtn.addEventListener('click', clearProductSelection);

  if (productSearchInput && productSuggestions) {
    productSearchInput.addEventListener('input', function() {
      var q = this.value.trim().toLowerCase();
      clearTimeout(prodSearchTimeout);

      if (q.length === 0) {
        productSuggestions.style.display = 'none';
        productSuggestions.innerHTML = '';
        return;
      }

      if (productSearchSpinner) productSearchSpinner.style.display = 'inline-block';

      prodSearchTimeout = setTimeout(function() {
        // First filter instant local catalog
        var matches = catalogProducts.filter(function(p) {
          return p.product_name.toLowerCase().indexOf(q) !== -1 ||
                 p.product_category.toLowerCase().indexOf(q) !== -1 ||
                 (p.sku && p.sku.toLowerCase().indexOf(q) !== -1);
        });

        if (productSearchSpinner) productSearchSpinner.style.display = 'none';
        renderProductSuggestions(matches);
      }, 150);
    });

    productSearchInput.addEventListener('focus', function() {
      var q = this.value.trim().toLowerCase();
      if (q.length > 0) {
        var matches = catalogProducts.filter(function(p) {
          return p.product_name.toLowerCase().indexOf(q) !== -1 ||
                 p.product_category.toLowerCase().indexOf(q) !== -1;
        });
        renderProductSuggestions(matches);
      }
    });

    document.addEventListener('click', function(e) {
      if (!productSearchInput.contains(e.target) && !productSuggestions.contains(e.target)) {
        productSuggestions.style.display = 'none';
      }
    });
  }

  // ========================================================
  // 4. ADD ITEM TO LINE ITEMS TABLE
  // ========================================================
  if (addItemBtn) {
    addItemBtn.addEventListener('click', function() {
      if (pickerAlert) pickerAlert.style.display = 'none';

      if (!activeProductSelection) {
        // Attempt to match typed text
        var term = productSearchInput ? productSearchInput.value.trim().toLowerCase() : '';
        if (term) {
          var matched = catalogProducts.filter(function(p) {
            return p.product_name.toLowerCase() === term && p.in_stock;
          });
          if (matched.length > 0) {
            activeProductSelection = matched[0];
          }
        }
      }

      if (!activeProductSelection) {
        showPickerAlert('Please search and select a Pet Store product from the suggestions list.');
        return;
      }

      var p = activeProductSelection;
      var bpId = p.business_product_id;
      var name = p.product_name;
      var price = p.selling_price;
      var stock = p.available_stock;
      var qty = parseInt(productAddQty.value, 10);

      if (isNaN(qty) || qty <= 0) {
        showPickerAlert('Please enter a valid positive quantity.');
        return;
      }

      if (stock <= 0) {
        showPickerAlert('Selected product is currently out of stock.');
        return;
      }

      // Check if item is already added; consolidate quantity
      var existing = null;
      for (var i = 0; i < lineItems.length; i++) {
        if (lineItems[i].bpId === bpId) {
          existing = lineItems[i];
          break;
        }
      }

      if (existing) {
        var updatedQty = existing.qty + qty;
        if (updatedQty > stock) {
          showPickerAlert('Total requested quantity (' + updatedQty + ') exceeds available stock (' + stock + ').');
          return;
        }
        existing.qty = updatedQty;
      } else {
        if (qty > stock) {
          showPickerAlert('Requested quantity (' + qty + ') exceeds available stock (' + stock + ').');
          return;
        }
        lineItems.push({
          bpId: bpId,
          name: name,
          price: price,
          stock: stock,
          qty: qty
        });
      }

      clearProductSelection();
      productAddQty.value = '1';
      renderItems();
    });
  }

  function renderItems() {
    itemsTbody.innerHTML = '';
    if (lineItems.length === 0) {
      itemsTbody.appendChild(emptyRow);
      overallTotalEl.textContent = '₱0.00';
      updateConfirmButtonState();
      return;
    }

    var overallTotal = 0;

    lineItems.forEach(function(item, idx) {
      var tr = document.createElement('tr');
      tr.style.borderBottom = '1px solid var(--color-border, #f3f4f6)';

      var tdName = document.createElement('td');
      tdName.style.padding = '0.6rem 0.75rem';
      tdName.innerHTML = '<strong>' + escapeHtml(item.name) + '</strong><br><span class="caption muted">Stock available: ' + item.stock + '</span>';

      var hiddenBp = document.createElement('input');
      hiddenBp.type = 'hidden';
      hiddenBp.name = 'product_id[]';
      hiddenBp.value = item.bpId;
      tdName.appendChild(hiddenBp);

      var hiddenQty = document.createElement('input');
      hiddenQty.type = 'hidden';
      hiddenQty.name = 'quantity[]';
      hiddenQty.value = item.qty;
      tdName.appendChild(hiddenQty);

      var tdPrice = document.createElement('td');
      tdPrice.style.padding = '0.6rem 0.75rem';
      tdPrice.style.textAlign = 'right';
      tdPrice.textContent = '₱' + item.price.toFixed(2);

      var tdQty = document.createElement('td');
      tdQty.style.padding = '0.6rem 0.75rem';
      tdQty.style.textAlign = 'center';

      var qtyInput = document.createElement('input');
      qtyInput.type = 'number';
      qtyInput.min = '1';
      qtyInput.max = String(item.stock);
      qtyInput.value = String(item.qty);
      qtyInput.style.width = '65px';
      qtyInput.style.textAlign = 'center';
      qtyInput.addEventListener('change', function() {
        var newQ = parseInt(this.value, 10);
        if (isNaN(newQ) || newQ < 1) newQ = 1;
        if (newQ > item.stock) {
          alert('Requested quantity exceeds available stock (' + item.stock + ').');
          newQ = item.stock;
        }
        item.qty = newQ;
        renderItems();
      });
      tdQty.appendChild(qtyInput);

      var lineTotal = item.price * item.qty;
      overallTotal += lineTotal;

      var tdLineTot = document.createElement('td');
      tdLineTot.style.padding = '0.6rem 0.75rem';
      tdLineTot.style.textAlign = 'right';
      tdLineTot.style.fontWeight = '600';
      tdLineTot.textContent = '₱' + lineTotal.toFixed(2);

      var tdAction = document.createElement('td');
      tdAction.style.padding = '0.6rem 0.75rem';
      tdAction.style.textAlign = 'center';

      var removeBtn = document.createElement('button');
      removeBtn.type = 'button';
      removeBtn.className = 'btn ghost btn-sm';
      removeBtn.style.color = 'var(--color-danger, #ef4444)';
      removeBtn.style.padding = '0.2rem 0.5rem';
      removeBtn.innerHTML = '&times;';
      removeBtn.title = 'Remove item';
      removeBtn.addEventListener('click', function() {
        lineItems.splice(idx, 1);
        renderItems();
      });
      tdAction.appendChild(removeBtn);

      tr.appendChild(tdName);
      tr.appendChild(tdPrice);
      tr.appendChild(tdQty);
      tr.appendChild(tdLineTot);
      tr.appendChild(tdAction);

      itemsTbody.appendChild(tr);
    });

    overallTotalEl.textContent = '₱' + overallTotal.toFixed(2);
    updateConfirmButtonState();
  }

  function updateConfirmButtonState() {
    if (!confirmBtn) return;
    var hasItems = lineItems.length > 0;
    var isRegistered = typeRegisteredRadio && typeRegisteredRadio.checked;
    var hasValidCustomer = true;

    if (isRegistered) {
      var cid = saleCustomerId ? saleCustomerId.value.trim() : '';
      hasValidCustomer = (cid !== '' && !isNaN(parseInt(cid, 10)));
      if (regWarning) regWarning.style.display = hasValidCustomer ? 'none' : 'block';
    } else {
      if (regWarning) regWarning.style.display = 'none';
    }

    confirmBtn.disabled = (!hasItems || !hasValidCustomer);
  }

  function showPickerAlert(msg) {
    if (pickerAlert) {
      pickerAlert.textContent = msg;
      pickerAlert.style.display = 'block';
    }
  }

  function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // Double-submission protection & final validation
  if (saleForm) {
    saleForm.addEventListener('submit', function(e) {
      if (lineItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one product before confirming the sale.');
        return;
      }
      var isRegistered = typeRegisteredRadio && typeRegisteredRadio.checked;
      if (isRegistered) {
        var cid = saleCustomerId ? saleCustomerId.value.trim() : '';
        if (cid === '' || isNaN(parseInt(cid, 10))) {
          e.preventDefault();
          alert('Please search and select a valid registered customer before confirming the sale.');
          return;
        }
      }
      confirmBtn.disabled = true;
      confirmBtn.textContent = 'Processing Sale...';
    });
  }
})();
</script>

