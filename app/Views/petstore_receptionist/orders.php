<?php
$title = $title ?? 'Orders · Pet Store Receptionist';
$activeTab = $activeTab ?? 'all';
$statusFilter = $statusFilter ?? 'all';
$q = $searchQuery ?? '';

// Tab counts if needed or tabs definition
$tabs = [
    'all' => 'All Orders',
    'delivery' => 'Delivery Orders',
    'pickup' => 'Pickup Orders',
];

$buildTabUrl = function(string $t) use ($statusFilter, $q): string {
    $params = ['tab' => $t];
    if ($statusFilter !== 'all' && $statusFilter !== '') {
        $params['status'] = $statusFilter;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    return '/petstore/receptionist/orders?' . http_build_query($params);
};
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Pet Store Workspace</p>
    <h1>Orders</h1>
    <p class="muted">Manage over-the-counter customer pickup orders and coordinate delivery rescheduling with customers.</p>
  </div>
</div>

<!-- Exactly Three Tabs: All Orders, Delivery Orders, Pickup Orders -->
<nav class="tabs" style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; border-bottom: 1px solid var(--color-border, #e5e7eb);">
  <?php foreach ($tabs as $tabKey => $tabLabel): ?>
    <a href="<?= e($buildTabUrl($tabKey)) ?>"
       class="tab-link <?= $activeTab === $tabKey ? 'is-active' : '' ?>"
       style="padding: 0.65rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === $tabKey ? 'var(--color-primary, #047857)' : 'transparent' ?>; color: <?= $activeTab === $tabKey ? 'var(--color-primary, #047857)' : 'var(--color-muted, #6b7280)' ?>;">
      <?= e($tabLabel) ?>
    </a>
  <?php endforeach; ?>
</nav>

<!-- Filters & Search Bar -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/petstore/receptionist/orders" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">

    <div class="field" style="margin: 0; min-width: 260px; flex: 1;">
      <label for="order-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Order #, Customer, Phone, or Email</label>
      <input id="order-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search Order ID, Customer Name, Phone... (Press Enter)" class="search-input">
    </div>

    <div class="field" style="margin: 0; width: 200px;">
      <label for="status-filter" class="caption" style="display: block; margin-bottom: 0.25rem;">Filter by Status</label>
      <select id="status-filter" name="status" style="width: 100%;" onchange="this.form.submit()">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
        <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
        <option value="ready_for_pickup" <?= $statusFilter === 'ready_for_pickup' ? 'selected' : '' ?>>Ready for Pickup</option>
        <option value="out_for_delivery" <?= $statusFilter === 'out_for_delivery' ? 'selected' : '' ?>>Out for Delivery</option>
        <option value="delivery_failed" <?= $statusFilter === 'delivery_failed' ? 'selected' : '' ?>>Delivery Failed</option>
        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
      </select>
    </div>

    <?php if ($q !== '' || $statusFilter !== 'all'): ?>
      <div class="row" style="align-items: center;">
        <a href="/petstore/receptionist/orders?tab=<?= e($activeTab) ?>" class="btn ghost btn-sm">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<!-- Orders Table -->
<section class="card" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;"><?= e($tabs[$activeTab] ?? 'Pet Store Orders') ?> (<?= count($orders) ?>)</h2>
      <p class="caption muted" style="margin: 0.25rem 0 0 0;">
        Click any order row to inspect full order details, line items, delivery status, or advance pickup transitions.
      </p>
    </div>
  </div>

  <?php if (empty($orders)): ?>
    <div class="empty" style="padding: 3rem 1rem; text-align: center;">
      <p style="margin: 0; font-size: 1rem; color: var(--color-muted, #6b7280);">
        No orders found in <strong><?= e($tabs[$activeTab] ?? 'Orders') ?></strong> matching the specified criteria.
      </p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Order ID</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Customer</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Date &amp; Time</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Type</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: right;">Total Amount</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: center;">Status</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
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
            $isDelivery = $o['fulfillment_method'] === 'delivery';
            ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6); cursor: pointer;"
                data-modal-open="order-modal-<?= (int)$o['order_id'] ?>"
                class="order-table-row">
              <td style="padding: 0.875rem 1rem; font-weight: 600; font-family: var(--font-mono, monospace);">
                #ORD-<?= (int)$o['order_id'] ?>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <div style="font-weight: 500; color: var(--color-text, #111827);">
                  <?= e($o['customer_name']) ?>
                </div>
                <div class="caption muted">
                  <?= e($o['customer_phone'] ?: $o['customer_email'] ?: 'No contact recorded') ?>
                </div>
              </td>
              <td style="padding: 0.875rem 1rem; white-space: nowrap;">
                <div><?= date('M j, Y', strtotime((string)$o['order_date'])) ?></div>
                <span class="caption muted"><?= date('g:i A', strtotime((string)$o['order_date'])) ?></span>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <?php if ($isDelivery): ?>
                  <span class="badge" style="background: #e0f2fe; color: #0369a1; font-weight: 600;">
                    🚚 Delivery
                  </span>
                <?php else: ?>
                  <span class="badge" style="background: #fef3c7; color: #92400e; font-weight: 600;">
                    🛍️ Pickup
                  </span>
                <?php endif; ?>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: right; font-weight: 700; color: var(--color-text, #111827);">
                ₱<?= number_format((float)$o['total_amount'], 2) ?>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: center;">
                <span class="badge <?= $badgeClass ?>">
                  <?= e(ucwords(str_replace('_', ' ', $st))) ?>
                </span>
                <?php if ($st === 'delivery_failed' && !empty($o['rescheduled_date'])): ?>
                  <div class="caption muted" style="color: #b91c1c; font-size: 0.75rem; margin-top: 0.2rem;">
                    Rescheduled: <?= date('M j', strtotime((string)$o['rescheduled_date'])) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: right;" onclick="event.stopPropagation();">
                <button type="button" class="btn ghost btn-sm" data-modal-open="order-modal-<?= (int)$o['order_id'] ?>">
                  View Details &rarr;
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- ======================================================== -->
<!-- ORDER DETAILS MODALS (One for each order or selected)    -->
<!-- ======================================================== -->
<?php
// Collect all orders that need modal dialogs rendered
$ordersForModals = $orders;
if (!empty($selectedOrder)) {
    $found = false;
    foreach ($ordersForModals as $m) {
        if ((int)$m['order_id'] === (int)$selectedOrder['order_id']) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $ordersForModals[] = $selectedOrder;
    }
}
?>

<?php foreach ($ordersForModals as $orderModal): ?>
  <?php
  $ordId = (int)$orderModal['order_id'];
  $isAutoOpen = (!empty($selectedOrder) && (int)$selectedOrder['order_id'] === $ordId) ? 'true' : 'false';
  $st = (string)$orderModal['order_status'];
  $isDelivery = $orderModal['fulfillment_method'] === 'delivery';
  $isPickup = $orderModal['fulfillment_method'] === 'pickup';
  ?>
  <dialog class="modal modal-wide" id="order-modal-<?= $ordId ?>" data-open-on-load="<?= $isAutoOpen ?>" aria-labelledby="modal-title-<?= $ordId ?>">
    <div class="modal-head">
      <div>
        <h3 id="modal-title-<?= $ordId ?>" style="margin: 0; font-size: 1.25rem;">
          Order #ORD-<?= $ordId ?> Details
        </h3>
        <span class="caption muted">Placed on <?= date('M j, Y g:i A', strtotime((string)$orderModal['order_date'])) ?></span>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
    </div>
    <div class="modal-body" style="padding: 1.5rem;">

      <!-- Top Summary Grid: Customer Info, Fulfillment, Status -->
      <div class="grid grid-3" style="gap: 1rem; margin-bottom: 1.5rem;">
        <!-- Customer Info -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Customer</span>
          <div style="font-weight: 600; font-size: 1rem; margin-top: 0.25rem; color: var(--color-text, #111827);">
            <?= e($orderModal['customer_name']) ?>
          </div>
          <div class="caption muted" style="margin-top: 0.25rem;">Customer ID: #<?= (int)$orderModal['customer_id'] ?></div>
          <?php if (!empty($orderModal['customer_phone'])): ?>
            <div class="caption" style="margin-top: 0.25rem;">📞 <?= e($orderModal['customer_phone']) ?></div>
          <?php endif; ?>
          <?php if (!empty($orderModal['customer_email'])): ?>
            <div class="caption" style="margin-top: 0.25rem;">✉️ <?= e($orderModal['customer_email']) ?></div>
          <?php endif; ?>
        </div>

        <!-- Fulfillment & Payment Info -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Fulfillment &amp; Payment</span>
          <div style="font-weight: 600; font-size: 1rem; margin-top: 0.25rem;">
            <?= $isDelivery ? '🚚 Home Delivery' : '🛍️ In-Store Pickup' ?>
          </div>
          <div class="caption muted" style="margin-top: 0.25rem;">
            Method: <?= e($orderModal['payment_method'] ?? 'Pending') ?>
          </div>
          <div class="caption" style="margin-top: 0.25rem;">
            Payment Status:
            <span class="badge <?= ($orderModal['payment_status'] ?? '') === 'completed' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 0.75rem;">
              <?= e(ucfirst($orderModal['payment_status'] ?? 'pending')) ?>
            </span>
          </div>
        </div>

        <!-- Status Card -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Current Status</span>
          <div style="margin-top: 0.25rem;">
            <span class="badge <?= in_array($st, ['completed', 'confirmed'], true) ? 'badge-success' : ($st === 'delivery_failed' ? 'badge-danger' : 'badge-info') ?>" style="font-size: 0.95rem; padding: 0.35rem 0.65rem;">
              <?= e(ucwords(str_replace('_', ' ', $st))) ?>
            </span>
          </div>
          <?php if ($isDelivery && !empty($orderModal['delivery_employee_name'])): ?>
            <div class="caption muted" style="margin-top: 0.4rem;">
              Assigned Courier: <strong><?= e($orderModal['delivery_employee_name']) ?></strong>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Delivery Details / Failure Notice (for Delivery Orders) -->
      <?php if ($isDelivery): ?>
        <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
          <h4 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; color: #166534;">Delivery Address &amp; Instructions</h4>
          <p style="margin: 0; font-size: 0.9rem; color: #1e293b;">
            <?= e(implode(', ', array_filter([
                $orderModal['delivery_address_line1'] ?? '',
                $orderModal['delivery_address_line2'] ?? '',
                $orderModal['delivery_city'] ?? '',
                $orderModal['delivery_province'] ?? '',
                $orderModal['delivery_postal_code'] ?? '',
            ]))) ?>
          </p>
          <?php if (!empty($orderModal['notes'])): ?>
            <p class="caption muted" style="margin: 0.4rem 0 0 0;">
              <strong>Delivery Notes:</strong> <?= e($orderModal['notes']) ?>
            </p>
          <?php endif; ?>
        </div>

        <?php if ($st === 'delivery_failed'): ?>
          <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 1.25rem; margin-bottom: 1.5rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
              <span style="font-size: 1.25rem;" aria-hidden="true">⚠️</span>
              <h4 style="margin: 0; font-size: 1rem; color: #991b1b;">Recorded Delivery Failure Information</h4>
            </div>
            <div class="grid grid-2" style="gap: 0.75rem; font-size: 0.9rem;">
              <div>
                <span class="caption muted" style="display: block;">Failure Reason:</span>
                <strong style="color: #b91c1c;"><?= e($orderModal['delivery_failure_reason'] ?: 'Customer unavailable / unreachable') ?></strong>
              </div>
              <div>
                <span class="caption muted" style="display: block;">Failed At:</span>
                <span><?= !empty($orderModal['delivery_failed_at']) ? date('M j, Y g:i A', strtotime((string)$orderModal['delivery_failed_at'])) : 'Recorded by Courier' ?></span>
              </div>
            </div>

            <?php if (!empty($orderModal['rescheduled_date'])): ?>
              <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px dashed #fca5a5;">
                <span class="caption muted">Current Rescheduled Date:</span>
                <strong style="color: #991b1b;"> <?= date('l, F j, Y', strtotime((string)$orderModal['rescheduled_date'])) ?></strong>
                <p class="caption muted" style="margin: 0.2rem 0 0 0;">
                  Order status remains Delivery Failed until Delivery Personnel dispatches the order.
                </p>
              </div>
            <?php endif; ?>

            <!-- Receptionist Coordination: Reschedule Form (Max 2 Calendar Days) -->
            <form method="post" action="/petstore/receptionist/orders/<?= $ordId ?>/reschedule" style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #fecaca;">
              <?= \App\Core\CSRF::field() ?>
              <label for="resched-date-<?= $ordId ?>" class="caption" style="display: block; font-weight: 600; color: #991b1b; margin-bottom: 0.35rem;">
                Coordinate Customer Rescheduling (Max 2 calendar days from today):
              </label>
              <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                <?php
                $phToday = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Manila'));
                $todayDate = $phToday->format('Y-m-d');
                $maxReschedDate = $phToday->modify('+2 days')->format('Y-m-d');
                ?>
                <input type="date"
                       id="resched-date-<?= $ordId ?>"
                       name="rescheduled_date"
                       min="<?= $todayDate ?>"
                       max="<?= $maxReschedDate ?>"
                       value="<?= e($orderModal['rescheduled_date'] ?? $todayDate) ?>"
                       required
                       style="padding: 0.45rem 0.65rem; border: 1px solid #fca5a5; border-radius: 6px; font-size: 0.9rem;">
                <button type="submit" class="btn primary btn-sm">
                  Save Rescheduled Date
                </button>
              </div>
              <p class="caption muted" style="margin: 0.4rem 0 0 0; font-size: 0.8rem;">
                Business rule: Rescheduling is allowed up to 2 calendar days into the future. Status remains <em>Delivery Failed</em> until authorized Delivery Personnel re-dispatches.
              </p>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- Ordered Products Table -->
      <h4 style="margin: 0 0 0.75rem 0; font-size: 1rem;">Ordered Products</h4>
      <div style="border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; overflow: hidden; margin-bottom: 1.5rem;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: var(--color-bg-subtle, #f9fafb); border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left;">
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600;">Product</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600;">SKU</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: center;">Qty</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: right;">Unit Price</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; font-weight: 600; text-align: right;">Line Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($orderModal['items'] ?? []) as $it): ?>
              <?php $lTot = (float)$it['unit_price'] * (int)$it['quantity']; ?>
              <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
                <td style="padding: 0.65rem 0.75rem; font-weight: 500; color: var(--color-text, #111827);">
                  <?= e($it['product_name']) ?>
                </td>
                <td style="padding: 0.65rem 0.75rem; font-family: var(--font-mono, monospace); font-size: 0.85rem;" class="muted">
                  <?= e($it['sku'] ?: '—') ?>
                </td>
                <td style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 600;">
                  <?= (int)$it['quantity'] ?>
                </td>
                <td style="padding: 0.65rem 0.75rem; text-align: right;">
                  ₱<?= number_format((float)$it['unit_price'], 2) ?>
                </td>
                <td style="padding: 0.65rem 0.75rem; text-align: right; font-weight: 600;">
                  ₱<?= number_format($lTot, 2) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background: var(--color-bg-subtle, #f9fafb); border-top: 2px solid var(--color-border, #e5e7eb);">
              <th colspan="4" style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; font-size: 0.95rem;">
                Total Cost:
              </th>
              <th style="padding: 0.75rem 1rem; text-align: right; font-weight: 700; font-size: 1.15rem; color: var(--color-primary, #047857);">
                ₱<?= number_format((float)$orderModal['total_amount'], 2) ?>
              </th>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Receptionist Action Bar: Pickup Status Progression -->
      <?php if ($isPickup): ?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem 1.25rem;">
          <h4 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; color: #1e293b;">
            Pickup Order Status Management
          </h4>
          <p class="caption muted" style="margin: 0 0 0.75rem 0;">
            Follow the established in-store pickup flow: <code>Pending &rarr; Confirmed &rarr; Ready for Pickup &rarr; Completed</code>
          </p>

          <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <?php if ($st === 'pending'): ?>
              <form method="post" action="/petstore/receptionist/orders/<?= $ordId ?>/status" style="margin: 0;">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="status" value="confirmed">
                <button type="submit" class="btn primary btn-sm">
                  ✓ Confirm Order
                </button>
              </form>
            <?php elseif ($st === 'confirmed'): ?>
              <form method="post" action="/petstore/receptionist/orders/<?= $ordId ?>/status" style="margin: 0;">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="status" value="ready_for_pickup">
                <button type="submit" class="btn primary btn-sm">
                  🛍️ Mark Ready for Pickup
                </button>
              </form>
            <?php elseif ($st === 'ready_for_pickup'): ?>
              <form method="post" action="/petstore/receptionist/orders/<?= $ordId ?>/status" style="margin: 0;">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="btn primary btn-sm">
                  🎉 Complete In-Store Pickup
                </button>
              </form>
            <?php elseif ($st === 'completed'): ?>
              <span class="badge badge-success" style="padding: 0.4rem 0.75rem;">
                ✓ Order has been completed and released to customer.
              </span>
            <?php else: ?>
              <span class="caption muted">No further pickup transitions available for status: <?= e($st) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <!-- Delivery Order Notice for Receptionist -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem 1.25rem;">
          <h4 style="margin: 0 0 0.25rem 0; font-size: 0.95rem; color: #475569;">
            Delivery Personnel Management
          </h4>
          <p class="caption muted" style="margin: 0;">
            Out for Delivery dispatching, courier assignments, and delivery confirmations are performed exclusively by authorized Delivery Personnel. The Receptionist coordinates customer contact and delivery rescheduling.
          </p>
        </div>
      <?php endif; ?>

    </div>
  </dialog>
<?php endforeach; ?>

<style>
.order-table-row:hover {
  background: var(--color-bg-subtle, #f9fafb);
}
</style>

