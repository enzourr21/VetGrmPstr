<?php
require __DIR__ . '/_nav.php';
$invTab = $inventoryTab ?? 'stock';
$q = $searchQuery ?? '';
$isInvManager = !empty($isInventoryManagerOnly);
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Inventory Control</p>
    <h1>Inventory</h1>
    <p class="muted">Stock level monitoring, verified adjustments, purchase order tracking, and stock receiving records.</p>
  </div>
</div>

<!-- Sub-Tabs: Stock Levels vs Purchase Orders -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
  <div class="segmented-bar">
    <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=stock"
       class="segmented-pill <?= $invTab === 'stock' ? 'is-active' : '' ?>">
      Stock Levels &amp; Adjustments (<?= count($inventoryItems) ?>)
    </a>
    <a href="/manager/inventory<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=orders"
       class="segmented-pill <?= $invTab === 'orders' ? 'is-active' : '' ?>">
      Purchase Orders (<?= count($purchaseOrders) ?>)
    </a>
  </div>

  <?php if ($invTab === 'stock'): ?>
    <!-- Inventory Key Action, Search Field, and Request Order Action -->
    <div class="row" style="gap: 0.75rem; align-items: center;">
      <?php if (!empty($canManageInventoryKey)): ?>
        <div class="inv-key-header-actions" style="display: inline-flex; align-items: center; gap: 0.5rem;">
          <?php if (empty($activeInventoryKey)): ?>
            <form method="post" action="/manager/inventory/generate-key<?= $unitQuery ?>" style="margin: 0; display: inline-flex;" id="generate-inv-key-form">
              <?= \App\Core\CSRF::field() ?>
              <input type="hidden" name="return_tab" value="<?= e($invTab) ?>">
              <button type="submit" class="btn primary btn-sm" id="generate-inv-key-btn" style="white-space: nowrap;">
                Generate Inventory Key
              </button>
            </form>
          <?php else: ?>
            <button type="button"
                    class="btn primary btn-sm"
                    id="view-inv-key-btn"
                    style="white-space: nowrap;"
                    data-modal-open="inventory-key-modal"
                    data-key-seconds="<?= (int)($activeInventoryKey['seconds_remaining'] ?? 0) ?>">
              View Key
            </button>
            <button type="button"
                    class="btn danger btn-sm"
                    id="header-revoke-inv-key-btn"
                    style="white-space: nowrap;"
                    data-modal-open="revoke-key-modal"
                    data-revoke-key-id="<?= (int)$activeInventoryKey['key_id'] ?>"
                    data-revoke-key-code="<?= e($activeInventoryKey['key_code']) ?>"
                    data-revoke-expires="<?= e($activeInventoryKey['expires_at_formatted'] ?? '') ?>">
              Revoke
            </button>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <form method="get" action="/manager/inventory" class="row" style="gap: 0.5rem; align-items: center; margin: 0;">
        <?php if (!empty($isSuper)): ?>
          <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
        <?php endif; ?>
        <input type="hidden" name="tab" value="stock">
        <div class="search-input-wrap" style="width: 240px;">
          <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search stock or SKU... (Enter)" class="search-input">
        </div>
        <?php if ($q !== ''): ?>
          <a href="/manager/inventory<?= $unitQuery ?>" class="btn ghost btn-sm">Reset</a>
        <?php endif; ?>
      </form>

      <?php if ($isInvManager): ?>
        <button type="button" class="btn secondary btn-sm" disabled title="The Request Order workflow is managed via procurement and not configured for this interface.">
          Request Order
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($invTab === 'stock'): ?>
  <!-- STOCK LEVELS TABLE -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;"><?= e($unitName) ?> Stock Levels</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">
          <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
            Veterinary Clinic animal-health inventory with batch number and expiration date tracking. Stock adjustments require mandatory audit reasons.
          <?php else: ?>
            Prioritizing out-of-stock items (A–Z) followed by in-stock inventory. Stock adjustments require mandatory audit reasons.
          <?php endif; ?>
        </p>
      </div>
      <span class="caption"><?= count($inventoryItems) ?> items</span>
    </div>

    <?php if (empty($inventoryItems)): ?>
      <div class="empty">
        No inventory items found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.
        <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
          <span class="caption muted" style="display: block; margin-top: 0.25rem;">Batches &amp; Expiry tracking active for Veterinary Clinic animal-health stock.</span>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>SKU / Code</th>
              <th>Category</th>
              <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
                <th>Batches &amp; Expiry</th>
              <?php endif; ?>
              <th>On Hand</th>
              <th>Committed</th>
              <th>Available</th>
              <th>Stock Status</th>
              <th style="text-align: right;">Stock Adjustment</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventoryItems as $item):
              $isOutOfStock = (int)$item['on_hand'] <= 0;
            ?>
              <tr style="<?= $isOutOfStock ? 'background-color: rgba(254, 242, 242, 0.4);' : '' ?>">
                <td>
                  <strong><?= e($item['product_name']) ?></strong>
                  <?php if ($isOutOfStock): ?>
                    <span class="badge badge-danger" style="margin-left: 0.4rem; font-size: 0.7rem;">Out of Stock</span>
                  <?php endif; ?>
                </td>
                <td><code><?= e($item['sku'] ?: '—') ?></code></td>
                <td><span class="badge secondary"><?= e($item['product_category']) ?></span></td>
                <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
                  <td>
                    <?php if (!empty($item['batches'])): ?>
                      <div style="display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.78rem;">
                        <?php foreach ($item['batches'] as $b):
                          $isExpired = !empty($b['expiration_date']) && strtotime((string)$b['expiration_date']) < time();
                          $isExpiringSoon = !empty($b['expiration_date']) && !$isExpired && (strtotime((string)$b['expiration_date']) - time() <= 30 * 86400);
                        ?>
                          <div style="display: inline-flex; align-items: center; gap: 0.35rem; flex-wrap: wrap;">
                            <code><?= e($b['batch_number']) ?></code>
                            <span class="muted">(<?= (int)$b['quantity'] ?>)</span>
                            <?php if (!empty($b['expiration_date'])): ?>
                              <span class="badge <?= $isExpired ? 'badge-danger' : ($isExpiringSoon ? 'warn' : 'secondary') ?>" style="font-size: 0.7rem; padding: 0.1rem 0.35rem;">
                                Exp: <?= date('M j, Y', strtotime((string)$b['expiration_date'])) ?>
                              </span>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <span class="caption muted">No batch tracked</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td>
                  <strong style="<?= $isOutOfStock ? 'color: var(--danger, #dc2626); font-size: 1.1rem;' : '' ?>">
                    <?= (int)$item['on_hand'] ?>
                  </strong>
                </td>
                <td><?= (int)$item['committed'] ?></td>
                <td><strong><?= (int)$item['available'] ?></strong></td>
                <td>
                  <span class="badge <?= $isOutOfStock ? 'badge-danger' : 'badge-success' ?>">
                    <?= $isOutOfStock ? 'Out of Stock' : 'In Stock' ?>
                  </span>
                </td>
                <td style="text-align: right;">
                  <div class="row" style="gap: 0.4rem; justify-content: flex-end; align-items: center;">
                    <!-- Increase Button (+) -->
                    <button type="button"
                            class="btn secondary btn-sm"
                            style="font-weight: bold; width: 34px; padding: 0.25rem 0;"
                            title="Increase Stock (+)"
                            data-modal-open="adjust-modal-inc-<?= (int)$item['business_product_id'] ?>">
                      +
                    </button>

                    <!-- Decrease Button (−): Disabled if on_hand <= 0 -->
                    <button type="button"
                            class="btn secondary btn-sm"
                            style="font-weight: bold; width: 34px; padding: 0.25rem 0;"
                            title="<?= $isOutOfStock ? 'Cannot decrease stock when on-hand is 0' : 'Decrease Stock (−)' ?>"
                            <?= $isOutOfStock ? 'disabled' : '' ?>
                            data-modal-open="adjust-modal-dec-<?= (int)$item['business_product_id'] ?>">
                      &minus;
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- Stock Adjustment Modals (Increase & Decrease) -->
  <?php foreach ($inventoryItems as $item): ?>
    <!-- INCREASE MODAL (+) -->
    <dialog class="modal" id="adjust-modal-inc-<?= (int)$item['business_product_id'] ?>" aria-labelledby="inc-title-<?= (int)$item['business_product_id'] ?>">
      <div class="modal-head">
        <h3 id="inc-title-<?= (int)$item['business_product_id'] ?>">Increase Stock: <?= e($item['product_name']) ?></h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <form method="post" action="/manager/inventory/adjust<?= $unitQuery ?>" class="stock-adj-form">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="business_product_id" value="<?= (int)$item['business_product_id'] ?>">
          <input type="hidden" name="adjustment_type" value="increase">

          <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <div class="row" style="justify-content: space-between;">
              <span class="caption">Current On-Hand:</span>
              <strong><?= (int)$item['on_hand'] ?></strong>
            </div>
            <div class="row" style="justify-content: space-between; margin-top: 0.25rem;">
              <span class="caption">SKU:</span>
              <code><?= e($item['sku'] ?: '—') ?></code>
            </div>
          </div>

          <div class="field">
            <label for="inc-qty-<?= (int)$item['business_product_id'] ?>">Quantity to Add *</label>
            <input id="inc-qty-<?= (int)$item['business_product_id'] ?>" name="quantity" type="number" min="1" required value="1">
          </div>

          <div class="field">
            <label for="inc-reason-<?= (int)$item['business_product_id'] ?>">Mandatory Reason for Increase *</label>
            <select id="inc-reason-<?= (int)$item['business_product_id'] ?>" name="reason" required class="inc-reason-select">
              <option value="">Select reason...</option>
              <option value="Returned product from refund">Returned product from refund</option>
              <option value="Returned product from cancelled order">Returned product from cancelled order</option>
              <option value="New stock received">New stock received</option>
              <option value="Other approved stock correction">Other approved stock correction</option>
            </select>
          </div>

          <!-- Inspection Check for Returns -->
          <div class="return-inspection-wrap" style="display: none; background: #fffbeb; border: 1px solid #fef3c7; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem;">
            <label style="display: flex; gap: 0.5rem; align-items: flex-start; cursor: pointer; font-size: 0.9rem;">
              <input type="checkbox" name="inspection_confirmed" value="1" class="inspection-checkbox" style="margin-top: 0.2rem;">
              <span><strong>Inspection Check Confirmed:</strong> Returned product has been inspected and confirmed undamaged and suitable for resale.</span>
            </label>

            <div class="field" style="margin-top: 0.75rem; margin-bottom: 0;">
              <label class="caption" style="display: block; margin-bottom: 0.25rem;">Related Order / Refund Reference # (Optional)</label>
              <input type="text" name="related_reference" placeholder="e.g. REF-1002 or ORD-2005" style="width: 100%;">
            </div>
          </div>

          <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary">Confirm Increase</button>
          </div>
        </form>
      </div>
    </dialog>

    <!-- DECREASE MODAL (−) -->
    <dialog class="modal" id="adjust-modal-dec-<?= (int)$item['business_product_id'] ?>" aria-labelledby="dec-title-<?= (int)$item['business_product_id'] ?>">
      <div class="modal-head">
        <h3 id="dec-title-<?= (int)$item['business_product_id'] ?>">Decrease Stock: <?= e($item['product_name']) ?></h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <form method="post" action="/manager/inventory/adjust<?= $unitQuery ?>">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="business_product_id" value="<?= (int)$item['business_product_id'] ?>">
          <input type="hidden" name="adjustment_type" value="decrease">

          <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem;">
            <div class="row" style="justify-content: space-between;">
              <span class="caption">Current On-Hand:</span>
              <strong><?= (int)$item['on_hand'] ?></strong>
            </div>
            <div class="row" style="justify-content: space-between; margin-top: 0.25rem;">
              <span class="caption">SKU:</span>
              <code><?= e($item['sku'] ?: '—') ?></code>
            </div>
          </div>

          <div class="field">
            <label for="dec-qty-<?= (int)$item['business_product_id'] ?>">Quantity to Deduct *</label>
            <input id="dec-qty-<?= (int)$item['business_product_id'] ?>" name="quantity" type="number" min="1" max="<?= (int)$item['on_hand'] ?>" required value="1">
          </div>

          <div class="field">
            <label for="dec-reason-<?= (int)$item['business_product_id'] ?>">Mandatory Reason for Decrease *</label>
            <select id="dec-reason-<?= (int)$item['business_product_id'] ?>" name="reason" required>
              <option value="">Select reason...</option>
              <option value="Damaged product">Damaged product</option>
              <option value="Missing or lost product">Missing or lost product</option>
              <option value="Physical stock count correction">Physical stock count correction</option>
              <option value="Other approved stock correction">Other approved stock correction</option>
            </select>
          </div>

          <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn danger">Confirm Deduction</button>
          </div>
        </form>
      </div>
    </dialog>
  <?php endforeach; ?>

<?php else: ?>
  <!-- PURCHASE ORDERS & RECEIVING TABLE -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Purchase Orders &amp; Stock Receiving</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Purchase orders do not increase stock upon creation; only accepted received quantities update inventory.</p>
      </div>
      <div>
        <?php if (!$isInvManager): ?>
          <button type="button" class="btn primary btn-sm" data-modal-open="po-create-modal">
            + Purchase Order
          </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (empty($purchaseOrders)): ?>
      <div class="empty">No purchase orders found. Create a purchase order to initiate stock replenishment.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>PO Number</th>
              <th>Supplier</th>
              <th>Order Date</th>
              <th>Ordered By</th>
              <th>Items</th>
              <th>Total Cost</th>
              <th>Status</th>
              <th style="text-align: right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($purchaseOrders as $po): ?>
              <tr>
                <td><strong><?= e($po['po_number']) ?></strong></td>
                <td>
                  <strong><?= e($po['supplier_name']) ?></strong>
                  <div class="caption"><?= e($po['contact_person']) ?></div>
                </td>
                <td><?= e(date('M j, Y', strtotime((string)$po['order_date']))) ?></td>
                <td><?= e($po['created_by_name']) ?></td>
                <td><?= (int)$po['total_ordered_qty'] ?> unit(s)</td>
                <td><?= peso((float)$po['total_cost']) ?></td>
                <td>
                  <span class="badge <?= $po['po_status'] === 'Fully Received' ? 'badge-success' : ($po['po_status'] === 'Partially Received' ? 'badge-warning' : 'secondary') ?>">
                    <?= e($po['po_status']) ?>
                  </span>
                </td>
                <td style="text-align: right;">
                  <div class="row" style="gap: 0.35rem; justify-content: flex-end;">
                    <button type="button" class="btn ghost btn-sm" data-modal-open="view-po-<?= (int)$po['purchase_order_id'] ?>">
                      View Details
                    </button>
                    <?php if (in_array($po['po_status'], ['Ordered', 'Partially Received'], true)): ?>
                      <button type="button" class="btn secondary btn-sm" data-modal-open="receive-po-<?= (int)$po['purchase_order_id'] ?>">
                        Receive Stock
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- View PO Details & Stock Receiving Modals -->
  <?php foreach ($purchaseOrders as $po): ?>
    <!-- PO Details Modal -->
    <dialog class="modal modal-wide" id="view-po-<?= (int)$po['purchase_order_id'] ?>" aria-labelledby="view-po-title-<?= (int)$po['purchase_order_id'] ?>">
      <div class="modal-head">
        <div>
          <span class="badge secondary" style="margin-bottom: 0.25rem;">Purchase Order</span>
          <h3 id="view-po-title-<?= (int)$po['purchase_order_id'] ?>" style="margin: 0;"><?= e($po['po_number']) ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <div class="grid grid-2" style="background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; gap: 1rem;">
          <div>
            <p class="caption" style="margin: 0 0 0.25rem 0;">Supplier</p>
            <strong><?= e($po['supplier_name']) ?></strong>
            <div class="caption">Contact: <?= e($po['contact_person']) ?></div>
            <div class="caption">Email: <?= e($po['supplier_email'] ?: '—') ?> · Phone: <?= e($po['supplier_phone'] ?: '—') ?></div>
          </div>
          <div>
            <p class="caption" style="margin: 0 0 0.25rem 0;">Order Metadata</p>
            <div>Date: <strong><?= e(date('F j, Y g:i A', strtotime((string)$po['order_date']))) ?></strong></div>
            <div>Created By: <strong><?= e($po['created_by_name']) ?></strong></div>
            <div>Status: <span class="badge secondary"><?= e($po['po_status']) ?></span></div>
          </div>
        </div>

        <h4>Ordered Items</h4>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Qty Ordered</th>
                <th>Qty Received</th>
                <th>Outstanding</th>
                <th>Unit Cost</th>
                <th>Line Cost</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($po['items'] as $it): ?>
                <tr>
                  <td><strong><?= e($it['product_name']) ?></strong></td>
                  <td><code><?= e($it['sku'] ?: '—') ?></code></td>
                  <td><?= (int)$it['quantity_ordered'] ?></td>
                  <td><?= (int)$it['quantity_received'] ?></td>
                  <td><strong><?= max(0, (int)$it['quantity_ordered'] - (int)$it['quantity_received']) ?></strong></td>
                  <td><?= peso((float)$it['unit_cost']) ?></td>
                  <td><?= peso((float)$it['unit_cost'] * (int)$it['quantity_ordered']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (!empty($po['receivings'])): ?>
          <h4>Receiving Records (<?= count($po['receivings']) ?>)</h4>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Receiving Ref</th>
                  <th>Date</th>
                  <th>Received By</th>
                  <th>Product</th>
                  <th>Received</th>
                  <th>Accepted</th>
                  <th>Rejected</th>
                  <th>Rejection Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($po['receivings'] as $rcv): ?>
                  <tr>
                    <td><strong><?= e($rcv['receiving_reference']) ?></strong></td>
                    <td class="caption"><?= e(date('M j, Y g:i A', strtotime((string)$rcv['receiving_date']))) ?></td>
                    <td><?= e($rcv['employee_name']) ?></td>
                    <td><?= e($rcv['product_name']) ?></td>
                    <td><?= (int)$rcv['quantity_received'] ?></td>
                    <td><strong style="color: var(--success, #16a34a);"><?= (int)$rcv['quantity_accepted'] ?></strong></td>
                    <td><?= (int)$rcv['quantity_rejected'] ?></td>
                    <td class="caption"><?= e($rcv['rejection_reason'] ?: '—') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
      </div>
    </dialog>

    <!-- Receive Stock Modal -->
    <dialog class="modal modal-wide" id="receive-po-<?= (int)$po['purchase_order_id'] ?>" aria-labelledby="rcv-po-title-<?= (int)$po['purchase_order_id'] ?>">
      <div class="modal-head">
        <h3 id="rcv-po-title-<?= (int)$po['purchase_order_id'] ?>">Receive Stock for <?= e($po['po_number']) ?></h3>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <p class="caption" style="margin-bottom: 1rem;">
          Record incoming shipment. <strong>Important:</strong> Only accepted quantities increase on-hand inventory. Rejected items are logged with reason and do not enter stock.
        </p>

        <form method="post" action="/manager/inventory/purchase-orders/<?= (int)$po['purchase_order_id'] ?>/receive<?= $unitQuery ?>">
          <?= \App\Core\CSRF::field() ?>

          <div class="table-wrap" style="margin-bottom: 1.5rem;">
            <table>
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Ordered</th>
                  <th>Prev Recv</th>
                  <th>Qty Received Now *</th>
                  <th>Qty Accepted *</th>
                  <th>Qty Rejected</th>
                  <th>Rejection Reason (if any)</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($po['items'] as $idx => $it):
                  $remaining = max(0, (int)$it['quantity_ordered'] - (int)$it['quantity_received']);
                ?>
                  <tr>
                    <td>
                      <strong><?= e($it['product_name']) ?></strong>
                      <input type="hidden" name="items[<?= $idx ?>][po_item_id]" value="<?= (int)$it['po_item_id'] ?>">
                      <input type="hidden" name="items[<?= $idx ?>][business_product_id]" value="<?= (int)$it['business_product_id'] ?>">
                    </td>
                    <td><?= (int)$it['quantity_ordered'] ?></td>
                    <td><?= (int)$it['quantity_received'] ?></td>
                    <td>
                      <input type="number" name="items[<?= $idx ?>][quantity_received]" min="0" value="<?= $remaining ?>" style="width: 80px;" required>
                    </td>
                    <td>
                      <input type="number" name="items[<?= $idx ?>][quantity_accepted]" min="0" value="<?= $remaining ?>" style="width: 80px;" required>
                    </td>
                    <td>
                      <input type="number" name="items[<?= $idx ?>][quantity_rejected]" min="0" value="0" style="width: 80px;">
                    </td>
                    <td>
                      <input type="text" name="items[<?= $idx ?>][rejection_reason]" placeholder="Reason if rejected..." style="width: 160px;">
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="field">
            <label for="rcv-notes-<?= (int)$po['purchase_order_id'] ?>">Receiving Notes (Optional)</label>
            <input id="rcv-notes-<?= (int)$po['purchase_order_id'] ?>" name="notes" type="text" placeholder="e.g. Delivered by carrier, package inspected in good condition.">
          </div>

          <div class="modal-foot" style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb); display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary">Accept &amp; Update Inventory</button>
          </div>
        </form>
      </div>
    </dialog>
  <?php endforeach; ?>
<?php endif; ?>

<!-- MULTI-STEP PURCHASE ORDER CREATION MODAL -->
<dialog class="modal modal-wide" id="po-create-modal" aria-labelledby="po-modal-title">
  <div class="modal-head">
    <div>
      <span class="badge secondary" style="margin-bottom: 0.25rem;">Multi-Step Order</span>
      <h3 id="po-modal-title" style="margin: 0;">Create Purchase Order</h3>
    </div>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <!-- Step Indicator -->
    <div style="display: flex; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border, #e5e7eb); padding-bottom: 0.75rem;">
      <div id="step-1-indicator" style="font-weight: 600; color: var(--primary, #0284c7);">
        Step 1: Product Selection
      </div>
      <div style="color: var(--muted, #9ca3af);">&rarr;</div>
      <div id="step-2-indicator" style="color: var(--muted, #9ca3af);">
        Step 2: Supplier Information
      </div>
    </div>

    <form method="post" action="/manager/inventory/purchase-orders<?= $unitQuery ?>" id="multi-step-po-form">
      <?= \App\Core\CSRF::field() ?>

      <!-- STEP 1: PRODUCT SELECTION -->
      <div id="po-step-1">
        <p class="caption" style="margin-bottom: 1rem;">Select products and quantities to include in this purchase order. Continue is enabled once at least one product has been added.</p>

        <div id="po-items-container" style="margin-bottom: 1rem;">
          <!-- Dynamically populated or empty initially -->
        </div>

        <div style="margin-bottom: 1.5rem;">
          <button type="button" class="btn secondary btn-sm" id="po-add-product-btn">
            + Add Product
          </button>
        </div>

        <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb);">
          <button type="button" class="btn ghost" data-modal-close>Cancel</button>
          <button type="button" class="btn primary" id="po-step-1-continue-btn" disabled>
            Continue to Supplier Info &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 2: SUPPLIER INFORMATION -->
      <div id="po-step-2" style="display: none;">
        <p class="caption" style="margin-bottom: 1rem;">
          Enter supplier details. Supplier Name and Contact Person are required. At least one contact method (Email or Phone) is required.
        </p>

        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1rem;">
          <div class="field" style="margin-bottom: 0;">
            <label for="po-supplier-name">Supplier Name *</label>
            <input id="po-supplier-name" name="supplier_name" type="text" required placeholder="e.g. Metro Pet Distribution Corp.">
          </div>
          <div class="field" style="margin-bottom: 0;">
            <label for="po-contact-person">Contact Person *</label>
            <input id="po-contact-person" name="contact_person" type="text" required placeholder="e.g. Maria Santos">
          </div>
        </div>

        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1rem;">
          <div class="field" style="margin-bottom: 0;">
            <label for="po-supplier-email">Supplier Email (Email OR Phone required)</label>
            <input id="po-supplier-email" name="supplier_email" type="email" placeholder="e.g. orders@metropet.ph">
          </div>
          <div class="field" style="margin-bottom: 0;">
            <label for="po-supplier-phone">Supplier Phone (Email OR Phone required)</label>
            <input id="po-supplier-phone" name="supplier_phone" type="tel" placeholder="e.g. 0917-123-4567 or 8123-4567">
          </div>
        </div>

        <div class="field" style="margin-bottom: 1rem;">
          <label for="po-notes">Order Notes (Optional)</label>
          <input id="po-notes" name="notes" type="text" placeholder="e.g. Routine monthly replenishment shipment">
        </div>

        <!-- Non-editable confirmation summary -->
        <div style="background: var(--bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; margin-bottom: 1rem;">
          <div class="caption">System-generated upon confirmation:</div>
          <div class="row" style="gap: 1.5rem; margin-top: 0.25rem; font-size: 0.85rem;">
            <span>PO Number: <strong>[Auto-generated]</strong></span>
            <span>Order Date: <strong><?= date('M j, Y') ?></strong></span>
            <span>Created By: <strong><?= e(\App\Core\Session::get('email', 'Current User')) ?></strong></span>
            <span>Initial Status: <strong>Ordered</strong></span>
          </div>
        </div>

        <div class="modal-foot" style="display: flex; justify-content: space-between; gap: 0.5rem; padding-top: 1rem; border-top: 1px solid var(--border, #e5e7eb);">
          <button type="button" class="btn ghost" id="po-step-2-back-btn">&larr; Back to Products</button>
          <div class="row" style="gap: 0.5rem;">
            <button type="button" class="btn ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary" id="po-confirm-order-btn" disabled>
              Confirm Order
            </button>
          </div>
        </div>
      </div>
    </form>
  </div>
</dialog>

<!-- Template for PO Product Options -->
<template id="po-product-options-template">
  <option value="">-- Choose Product --</option>
  <?php foreach ($catalogProducts as $cp): ?>
    <option value="<?= (int)$cp['business_product_id'] ?>" data-price="<?= (float)$cp['selling_price'] ?>">
      <?= e($cp['product_name']) ?> (<?= e($cp['sku'] ?: 'No SKU') ?>)
    </option>
  <?php endforeach; ?>
</template>

<?php if (!empty($canManageInventoryKey) && !empty($activeInventoryKey)): ?>
  <?php
    $remSecs = max(0, (int)($activeInventoryKey['seconds_remaining'] ?? 0));
    $remMins = intdiv($remSecs, 60);
    $remSecPart = $remSecs % 60;
    $remDisplay = $remMins > 0 ? "{$remMins}m {$remSecPart}s" : "{$remSecPart}s";
  ?>
  <dialog class="modal"
          id="inventory-key-modal"
          aria-labelledby="inv-key-modal-title"
          <?= !empty($autoOpenKeyModal) ? 'data-open-on-load="true"' : '' ?>>
    <div class="modal-head">
      <div>
        <span class="badge badge-success" style="margin-bottom: 0.25rem;">Active Temporary Key</span>
        <h3 id="inv-key-modal-title" style="margin: 0;">Inventory Access Key</h3>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="inv-key-code-box">
        <div>
          <div class="caption" style="margin-bottom: 0.2rem;">Temporary Access Key</div>
          <code id="inv-key-code-value" class="inv-key-code-text"><?= e($activeInventoryKey['key_code']) ?></code>
        </div>
        <div style="display: flex; gap: 0.5rem; align-items: center;">
          <button type="button"
                  class="btn danger btn-sm"
                  id="modal-revoke-inv-key-btn"
                  data-modal-open="revoke-key-modal"
                  data-revoke-key-id="<?= (int)$activeInventoryKey['key_id'] ?>"
                  data-revoke-key-code="<?= e($activeInventoryKey['key_code']) ?>"
                  data-revoke-expires="<?= e($activeInventoryKey['expires_at_formatted'] ?? '') ?>">
            Revoke Key
          </button>
          <button type="button"
                  class="btn secondary btn-sm"
                  id="copy-inv-key-btn"
                  data-copy-key="<?= e($activeInventoryKey['key_code']) ?>">
            Copy Key
          </button>
        </div>
      </div>

      <div class="inv-key-details-grid">
        <div class="inv-key-detail-row">
          <span class="caption">Business Unit</span>
          <strong><?= e($activeInventoryKey['business_unit_name'] ?? $unitName) ?></strong>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Generated By</span>
          <strong><?= e($activeInventoryKey['created_by_name'] ?? 'Business Manager') ?></strong>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Generated At</span>
          <span><?= e($activeInventoryKey['created_at_formatted'] ?? $activeInventoryKey['created_at']) ?></span>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Expires At</span>
          <span><?= e($activeInventoryKey['expires_at_formatted'] ?? $activeInventoryKey['expires_at']) ?></span>
        </div>
        <div class="inv-key-detail-row">
          <span class="caption">Remaining Validity</span>
          <strong id="inv-key-countdown" data-seconds-remaining="<?= $remSecs ?>"><?= e($remDisplay) ?></strong>
        </div>
      </div>
    </div>
    <div class="modal-foot" style="display: flex; justify-content: flex-end; align-items: center; gap: 0.5rem;">
      <button type="button" class="btn primary btn-sm" data-modal-close>Done</button>
    </div>
  </dialog>
<?php endif; ?>

<?php if (!empty($canManageInventoryKey)): ?>
  <!-- Revocation Confirmation Dialog -->
  <dialog class="modal" id="revoke-key-modal" aria-labelledby="revoke-key-modal-title" style="max-width: 520px;">
    <div class="modal-head">
      <div>
        <span class="badge badge-danger" style="margin-bottom: 0.25rem;">Confirm Revocation</span>
        <h3 id="revoke-key-modal-title" style="margin: 0; color: var(--danger, #dc2626);">Revoke Inventory Access Key</h3>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p style="font-size: 0.95rem; margin: 0 0 1rem 0; line-height: 1.5;">
        Are you sure you want to revoke this Inventory Access Key?
      </p>
      <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 0.85rem 1rem; margin-bottom: 1rem;">
        <div style="margin-bottom: 0.35rem;">
          <span class="caption" style="color: var(--muted); display: block;">Key Code</span>
          <code class="inv-key-code-text" style="font-size: 1.15rem; font-weight: 700;" id="revoke-confirm-key-code">
            <?= e($activeInventoryKey['key_code'] ?? '—') ?>
          </code>
        </div>
        <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; margin-top: 0.5rem; border-top: 1px solid var(--line); padding-top: 0.5rem;">
          <div>
            <span class="caption" style="color: var(--muted); display: block;">Business Unit</span>
            <strong><?= e($unitName) ?></strong>
          </div>
          <div>
            <span class="caption" style="color: var(--muted); display: block;">Expires At</span>
            <span id="revoke-confirm-expires"><?= e($activeInventoryKey['expires_at_formatted'] ?? '—') ?></span>
          </div>
        </div>
      </div>
      <p class="caption muted" style="margin: 0; line-height: 1.45;">
        Revoking this key immediately invalidates it. Any employee currently logged into temporary inventory access using this key will immediately have their access terminated. The key record will remain preserved in historical logs.
      </p>
    </div>
    <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
      <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
      <form method="post" action="/manager/inventory/revoke-key<?= $unitQuery ?>" style="margin: 0;" id="confirm-revoke-key-form">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="key_id" id="revoke-confirm-key-id" value="<?= (int)($activeInventoryKey['key_id'] ?? 0) ?>">
        <input type="hidden" name="return_tab" value="<?= e($invTab) ?>">
        <button type="submit" class="btn danger btn-sm" id="confirm-revoke-key-btn">
          Confirm Revocation
        </button>
      </form>
    </div>
  </dialog>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      document.querySelectorAll('[data-revoke-key-id]').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var kid = btn.getAttribute('data-revoke-key-id');
          var kcode = btn.getAttribute('data-revoke-key-code');
          var kexp = btn.getAttribute('data-revoke-expires');
          var idInput = document.getElementById('revoke-confirm-key-id');
          var codeEl = document.getElementById('revoke-confirm-key-code');
          var expEl = document.getElementById('revoke-confirm-expires');
          if (idInput && kid) idInput.value = kid;
          if (codeEl && kcode) codeEl.textContent = kcode;
          if (expEl && kexp) expEl.textContent = kexp;
        });
      });
    });
  </script>
<?php endif; ?>

