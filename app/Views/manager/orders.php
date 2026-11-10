<?php
require __DIR__ . '/_nav.php';
$q = $searchQuery ?? '';
$type = $typeFilter ?? 'all';
$status = $statusFilter ?? 'all';
$dateFromVal = $dateFrom ?? '';
$dateToVal = $dateTo ?? '';
$ordersList = $orders ?? [];
$totalOrders = count($ordersList);
?>

<div class="page-head" style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem;">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Order Monitoring</p>
    <h1>Orders</h1>
    <p class="muted">Read-only monitoring of customer online pickup and delivery orders for <?= e($unitName) ?>.</p>
  </div>
  <?php if (empty($isInventoryManagerOnly)): ?>
    <div>
      <a href="/manager/sales<?= $unitQuery ?>" class="btn secondary btn-sm">Walk-in Sales &amp; Refunds &rarr;</a>
    </div>
  <?php endif; ?>
</div>

<!-- Filtering & Search Bar -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/manager/orders" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>

    <div class="field" style="margin: 0; min-width: 220px; flex: 1;">
      <label for="order-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Order # or Customer</label>
      <div class="search-input-wrap">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input id="order-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search Order #, Customer, Email... (Press Enter)" class="search-input">
      </div>
    </div>

    <div class="field" style="margin: 0; width: 170px;">
      <label for="order-type" class="caption" style="display: block; margin-bottom: 0.25rem;">Fulfillment Type</label>
      <select id="order-type" name="type" style="width: 100%;" onchange="this.form.submit()">
        <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Types</option>
        <option value="pickup" <?= $type === 'pickup' ? 'selected' : '' ?>>Pickup Orders</option>
        <option value="delivery" <?= $type === 'delivery' ? 'selected' : '' ?>>Delivery Orders</option>
      </select>
    </div>

    <div class="field" style="margin: 0; width: 180px;">
      <label for="order-status" class="caption" style="display: block; margin-bottom: 0.25rem;">Order Status</label>
      <select id="order-status" name="status" style="width: 100%;" onchange="this.form.submit()">
        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
        <option value="processing" <?= $status === 'processing' ? 'selected' : '' ?>>Processing</option>
        <option value="ready_for_pickup" <?= $status === 'ready_for_pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
        <option value="out_for_delivery" <?= $status === 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="delivery_failed" <?= $status === 'delivery_failed' ? 'selected' : '' ?>>Delivery Failed</option>
        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      </select>
    </div>

    <div class="field" style="margin: 0; width: 140px;">
      <label for="order-date-from" class="caption" style="display: block; margin-bottom: 0.25rem;">From Date</label>
      <input id="order-date-from" type="date" name="date_from" value="<?= e($dateFromVal) ?>" style="width: 100%;" onchange="this.form.submit()">
    </div>

    <div class="field" style="margin: 0; width: 140px;">
      <label for="order-date-to" class="caption" style="display: block; margin-bottom: 0.25rem;">To Date</label>
      <input id="order-date-to" type="date" name="date_to" value="<?= e($dateToVal) ?>" style="width: 100%;" onchange="this.form.submit()">
    </div>

    <?php if ($q !== '' || $type !== 'all' || $status !== 'all' || $dateFromVal !== '' || $dateToVal !== ''): ?>
      <div class="row" style="align-items: center;">
        <a href="/manager/orders<?= $unitQuery ?>" class="btn ghost">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<!-- Orders Table -->
<section class="card">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0;">Online Orders (<?= $totalOrders ?>)</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">Strictly read-only monitoring. Order modifications, pickup confirmation, and delivery dispatches are handled by operational staff.</p>
    </div>
    <span class="caption"><?= $totalOrders ?> orders found</span>
  </div>

  <?php if (empty($ordersList)): ?>
    <div class="empty">No online orders found matching the selected criteria.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Order Number</th>
            <th>Date &amp; Time</th>
            <th>Customer</th>
            <th>Order Type</th>
            <th>Total Amount</th>
            <th>Payment</th>
            <th>Order Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ordersList as $o): ?>
            <tr>
              <td>
                <strong>#ORD-<?= (int)$o['order_id'] ?></strong>
                <div class="caption">ID #<?= (int)$o['order_id'] ?></div>
              </td>
              <td>
                <?= e(date('M j, Y', strtotime((string)$o['order_date']))) ?>
                <div class="caption"><?= e(date('g:i A', strtotime((string)$o['order_date']))) ?></div>
              </td>
              <td>
                <strong><?= e($o['customer_name']) ?></strong>
                <?php if (!empty($o['customer_phone'])): ?>
                  <div class="caption"><?= e($o['customer_phone']) ?></div>
                <?php elseif (!empty($o['customer_email'])): ?>
                  <div class="caption"><?= e($o['customer_email']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge secondary"><?= e(ucfirst((string)$o['fulfillment_method'])) ?></span>
              </td>
              <td><strong><?= peso((float)$o['total_amount']) ?></strong></td>
              <td>
                <span class="badge secondary"><?= e($o['payment_method'] ?? 'COD') ?></span>
                <div class="caption"><?= e(ucfirst((string)($o['payment_status'] ?? 'Completed'))) ?></div>
              </td>
              <td>
                <?php
                $st = (string)$o['order_status'];
                $badgeClass = 'badge-secondary';
                if (in_array($st, ['completed', 'confirmed'], true)) {
                    $badgeClass = 'badge-success';
                } elseif (in_array($st, ['ready_for_pickup', 'out_for_delivery', 'processing'], true)) {
                    $badgeClass = 'badge-info';
                } elseif ($st === 'pending') {
                    $badgeClass = 'badge-warning';
                } elseif (in_array($st, ['cancelled', 'delivery_failed'], true)) {
                    $badgeClass = 'badge-danger';
                }
                ?>
                <span class="badge <?= $badgeClass ?>">
                  <?= e(ucwords(str_replace('_', ' ', $st))) ?>
                </span>
              </td>
              <td>
                <button type="button" class="btn secondary btn-sm" data-modal-open="order-modal-<?= (int)$o['order_id'] ?>">
                  View Details
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- Read-Only Order Details Modals -->
<?php foreach ($ordersList as $o): ?>
  <dialog class="modal modal-wide" id="order-modal-<?= (int)$o['order_id'] ?>" aria-labelledby="order-title-<?= (int)$o['order_id'] ?>">
    <div class="modal-head">
      <div>
        <span class="badge" style="background: #fef3c7; color: #92400e; margin-bottom: 0.25rem;">
          Online <?= e(ucfirst((string)$o['fulfillment_method'])) ?> Order
        </span>
        <h3 id="order-title-<?= (int)$o['order_id'] ?>" style="margin: 0;">Order #ORD-<?= (int)$o['order_id'] ?></h3>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body">
      <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
        <div>
          <p style="margin: 0 0 0.25rem 0;" class="caption">Order Reference</p>
          <strong>#ORD-<?= (int)$o['order_id'] ?></strong>

          <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Order Date &amp; Time</p>
          <div><?= e(date('F j, Y · g:i:s A', strtotime((string)$o['order_date']))) ?></div>

          <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Current Order Status</p>
          <div>
            <?php
            $st = (string)$o['order_status'];
            $badgeClass = 'badge-secondary';
            if (in_array($st, ['completed', 'confirmed'], true)) {
                $badgeClass = 'badge-success';
            } elseif (in_array($st, ['ready_for_pickup', 'out_for_delivery', 'processing'], true)) {
                $badgeClass = 'badge-info';
            } elseif ($st === 'pending') {
                $badgeClass = 'badge-warning';
            } elseif (in_array($st, ['cancelled', 'delivery_failed'], true)) {
                $badgeClass = 'badge-danger';
            }
            ?>
            <span class="badge <?= $badgeClass ?>">
              <?= e(ucwords(str_replace('_', ' ', $st))) ?>
            </span>
          </div>
        </div>

        <div>
          <p style="margin: 0 0 0.25rem 0;" class="caption">Customer Details</p>
          <strong><?= e($o['customer_name']) ?></strong>
          <div class="caption"><?= e($o['customer_email'] ?? '') ?> · <?= e($o['customer_phone'] ?? '') ?></div>

          <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Order Type &amp; Fulfillment</p>
          <div><strong><?= e(ucfirst((string)$o['fulfillment_method'])) ?></strong></div>
          <?php if ($o['fulfillment_method'] === 'delivery'): ?>
            <div class="caption" style="margin-top: 0.25rem;">
              <strong>Delivery Address:</strong><br>
              <?= e($o['delivery_address_line1'] ?? '') ?>
              <?= !empty($o['delivery_address_line2']) ? ', ' . e($o['delivery_address_line2']) : '' ?>
              <?= !empty($o['delivery_city']) ? ', ' . e($o['delivery_city']) : '' ?>
              <?= !empty($o['delivery_province']) ? ', ' . e($o['delivery_province']) : '' ?>
              <?= !empty($o['delivery_postal_code']) ? ' ' . e($o['delivery_postal_code']) : '' ?>
            </div>
            <?php if (!empty($o['delivery_employee_name'])): ?>
              <div class="caption" style="margin-top: 0.25rem;">
                <strong>Assigned Courier:</strong> <?= e($o['delivery_employee_name']) ?>
              </div>
            <?php endif; ?>
            <?php if ($st === 'delivery_failed'): ?>
              <div class="alert alert-warning" style="margin-top: 0.5rem; padding: 0.5rem 0.75rem; font-size: 0.85rem;">
                <strong>Status Notice:</strong> Delivery attempt failed. Customer contact and dispatch rescheduling are managed by store reception and delivery operations.
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="caption" style="margin-top: 0.25rem;">
              <strong>Pickup Location:</strong> VETGRPET Pet Store (Main Branch)<br>
              Pickup preparation is managed by the front desk receptionist.
            </div>
          <?php endif; ?>

          <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Payment Information</p>
          <div>
            <span class="badge secondary"><?= e($o['payment_method'] ?? 'COD') ?></span>
            <span class="badge badge-success"><?= e(ucfirst((string)($o['payment_status'] ?? 'Completed'))) ?></span>
          </div>
        </div>
      </div>

      <h4 style="margin: 1rem 0 0.5rem 0;">Ordered Products (<?= count($o['items'] ?? []) ?>)</h4>
      <div class="table-wrap" style="margin-bottom: 1.5rem;">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>SKU</th>
              <th>Unit Price</th>
              <th>Quantity</th>
              <th style="text-align: right;">Line Total</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $calcSubtotal = 0.0;
            foreach ($o['items'] as $item):
              $lineTotal = (float)$item['unit_price'] * (int)$item['quantity'];
              $calcSubtotal += $lineTotal;
            ?>
              <tr>
                <td><strong><?= e($item['product_name']) ?></strong></td>
                <td><code><?= e($item['sku'] ?: '—') ?></code></td>
                <td><?= peso((float)$item['unit_price']) ?></td>
                <td><?= (int)$item['quantity'] ?></td>
                <td style="text-align: right;"><?= peso($lineTotal) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="display: flex; justify-content: flex-end;">
        <div style="min-width: 240px; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
          <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
            <span class="caption">Subtotal:</span>
            <span><?= peso($calcSubtotal > 0 ? $calcSubtotal : (float)$o['total_amount']) ?></span>
          </div>
          <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
            <span class="caption">Discount:</span>
            <span><?= peso(0.0) ?></span>
          </div>
          <hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid var(--border, #e5e7eb);">
          <div class="row" style="justify-content: space-between;">
            <strong>Order Total:</strong>
            <strong style="font-size: 1.15rem; color: var(--primary, #0284c7);"><?= peso((float)$o['total_amount']) ?></strong>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <span class="caption" style="margin-right: auto; color: var(--muted, #6b7280);">
        Read-only inspection view · No modifications permitted
      </span>
      <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
    </div>
  </dialog>
<?php endforeach; ?>

