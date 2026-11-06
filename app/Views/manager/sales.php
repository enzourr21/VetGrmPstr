<?php
require __DIR__ . '/_nav.php';
$activeSalesTab = $salesTab ?? 'walkin';
$q = $searchQuery ?? '';
$stFilter = $statusFilter ?? '';
$walkInSales = $walkInSales ?? [];
$onlineOrders = $onlineOrders ?? [];
$refundRequests = $refundRequests ?? [];
$pendingRefundsCount = $pendingRefundsCount ?? 0;
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Transaction Management</p>
    <h1>Sales</h1>
    <p class="muted">Complete transaction histories for Pet Store walk-in sales, online orders, and customer refund requests.</p>
  </div>
</div>

<!-- Switch-like Segmented Control for Sales Views -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
  <div class="segmented-bar" role="tablist" aria-label="Sales view selector">
    <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=walkin"
       class="segmented-pill <?= $activeSalesTab === 'walkin' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'walkin' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'walkin' ? 'var(--brand-soft, #e6f4f0)' : 'var(--bg-subtle, #f3f4f6)' ?>; color: <?= $activeSalesTab === 'walkin' ? 'var(--brand, #0d6e57)' : 'var(--muted, #6b7280)' ?>; border: 1px solid <?= $activeSalesTab === 'walkin' ? 'rgba(16, 185, 129, 0.35)' : 'var(--border, #e5e7eb)' ?>; font-size: 0.75rem; font-weight: 600;">Walk-in</span>
      Walk-in Sales (<?= count($walkInSales) ?>)
    </a>
    <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=online"
       class="segmented-pill <?= $activeSalesTab === 'online' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'online' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'online' ? 'var(--brand-soft, #e6f4f0)' : 'var(--bg-subtle, #f3f4f6)' ?>; color: <?= $activeSalesTab === 'online' ? 'var(--brand, #0d6e57)' : 'var(--muted, #6b7280)' ?>; border: 1px solid <?= $activeSalesTab === 'online' ? 'rgba(16, 185, 129, 0.35)' : 'var(--border, #e5e7eb)' ?>; font-size: 0.75rem; font-weight: 600;">Online</span>
      Online Orders (<?= count($onlineOrders) ?>)
    </a>
    <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=refunds"
       class="segmented-pill <?= $activeSalesTab === 'refunds' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'refunds' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'refunds' ? 'var(--brand-soft, #e6f4f0)' : 'var(--bg-subtle, #f3f4f6)' ?>; color: <?= $activeSalesTab === 'refunds' ? 'var(--brand, #0d6e57)' : 'var(--muted, #6b7280)' ?>; border: 1px solid <?= $activeSalesTab === 'refunds' ? 'rgba(16, 185, 129, 0.35)' : 'var(--border, #e5e7eb)' ?>; font-size: 0.75rem; font-weight: 600;">Refunds</span>
      Refund Requests (<?= (int)$pendingRefundsCount ?>)
    </a>
  </div>

  <!-- Search & Automatic Filtering Controls (No separate green Apply Filter button) -->
  <form method="get" action="/manager/sales" class="row" style="gap: 0.5rem; align-items: center;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>
    <input type="hidden" name="tab" value="<?= e($activeSalesTab) ?>">

    <?php if ($activeSalesTab === 'refunds'): ?>
      <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #d1d5db); background: var(--surface); font-size: 0.85rem;">
        <option value="">All Statuses</option>
        <option value="pending" <?= ($stFilter === 'pending') ? 'selected' : '' ?>>Pending Review</option>
        <option value="approved" <?= ($stFilter === 'approved') ? 'selected' : '' ?>>Approved</option>
        <option value="rejected" <?= ($stFilter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
      </select>
    <?php endif; ?>

    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= $activeSalesTab === 'refunds' ? 'Search ref, customer... (Press Enter)' : 'Search reference, customer... (Press Enter)' ?>" style="width: 260px; padding: 0.4rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #d1d5db); font-size: 0.85rem;">
    <?php if ($q !== '' || ($activeSalesTab === 'refunds' && $stFilter !== '')): ?>
      <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($activeSalesTab) ?>" class="btn ghost btn-sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($activeSalesTab === 'walkin'): ?>
  <!-- 1. WALK-IN SALES VIEW -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Walk-in Sales Transactions</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">In-store cashier transactions sorted by date and time (newest first).</p>
      </div>
      <span class="caption"><?= count($walkInSales) ?> records</span>
    </div>

    <?php if (empty($walkInSales)): ?>
      <div class="empty">No walk-in transactions found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Receipt Ref</th>
              <th>Date &amp; Time</th>
              <th>Customer</th>
              <th>Items</th>
              <th>Total Amount</th>
              <th>Payment Method</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($walkInSales as $s): ?>
              <tr>
                <td>
                  <strong>#REC-S-<?= (int)$s['sale_id'] ?></strong>
                  <div class="caption">Sale #<?= (int)$s['sale_id'] ?></div>
                </td>
                <td>
                  <?= e(date('M j, Y', strtotime((string)$s['sale_date']))) ?>
                  <div class="caption"><?= e(date('g:i:s A', strtotime((string)$s['sale_date']))) ?></div>
                </td>
                <td><?= e($s['customer_name']) ?></td>
                <td><?= (int)$s['item_count'] ?> item(s)</td>
                <td><strong><?= peso((float)$s['total_amount']) ?></strong></td>
                <td><span class="badge secondary"><?= e($s['payment_method'] ?? 'Cash') ?></span></td>
                <td>
                  <span class="badge <?= $s['sale_status'] === 'completed' ? 'badge-success' : 'badge-danger' ?>">
                    <?= e(ucfirst((string)$s['sale_status'])) ?>
                  </span>
                </td>
                <td>
                  <button type="button" class="btn secondary btn-sm" data-modal-open="walkin-modal-<?= (int)$s['sale_id'] ?>">
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

  <!-- Walk-in Sale Details Modals -->
  <?php foreach ($walkInSales as $s): ?>
    <dialog class="modal modal-wide" id="walkin-modal-<?= (int)$s['sale_id'] ?>" aria-labelledby="walkin-title-<?= (int)$s['sale_id'] ?>" <?= (!empty($viewId) && (int)$viewId === (int)$s['sale_id']) ? 'data-open-on-load="true"' : '' ?>>
      <div class="modal-head">
        <div>
          <span class="badge" style="background: #e0f2fe; color: #0369a1; margin-bottom: 0.25rem;">Walk-in Sale</span>
          <h3 id="walkin-title-<?= (int)$s['sale_id'] ?>" style="margin: 0;">Transaction #REC-S-<?= (int)$s['sale_id'] ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times; Close</button>
      </div>
      <div class="modal-body">
        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Transaction Reference</p>
            <strong>WALK-IN-REC-S-<?= (int)$s['sale_id'] ?></strong>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Date &amp; Time</p>
            <div><?= e(date('F j, Y · g:i:s A', strtotime((string)$s['sale_date']))) ?></div>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Receipt Reference</p>
            <code>REC-S-<?= (int)$s['sale_id'] ?></code>
          </div>
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Customer Information</p>
            <strong><?= e($s['customer_name']) ?></strong>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Fulfillment Channel</p>
            <div>In-Store Walk-in Counter Sale</div>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Payment Method &amp; Status</p>
            <div>
              <span class="badge secondary"><?= e($s['payment_method'] ?? 'Cash (In-store)') ?></span>
              <span class="badge badge-success"><?= e(ucfirst((string)($s['payment_status'] ?? 'Completed'))) ?></span>
            </div>
          </div>
        </div>

        <h4 style="margin: 1rem 0 0.5rem 0;">Purchased Products</h4>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Unit Price</th>
                <th>Qty</th>
                <th style="text-align: right;">Line Total</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $calcSubtotal = 0.0;
              foreach ($s['items'] as $item):
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
              <span><?= peso($calcSubtotal > 0 ? $calcSubtotal : (float)$s['total_amount']) ?></span>
            </div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Discount:</span>
              <span><?= peso(0.0) ?></span>
            </div>
            <hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid var(--border, #e5e7eb);">
            <div class="row" style="justify-content: space-between;">
              <strong>Final Total:</strong>
              <strong style="font-size: 1.15rem; color: var(--primary, #0284c7);"><?= peso((float)$s['total_amount']) ?></strong>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
      </div>
    </dialog>
  <?php endforeach; ?>

<?php elseif ($activeSalesTab === 'online'): ?>
  <!-- 2. ONLINE ORDERS VIEW -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Online Orders Transactions</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Customer e-commerce orders sorted by date and time (newest first).</p>
      </div>
      <span class="caption"><?= count($onlineOrders) ?> records</span>
    </div>

    <?php if (empty($onlineOrders)): ?>
      <div class="empty">No online orders found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Order Ref</th>
              <th>Date &amp; Time</th>
              <th>Customer</th>
              <th>Fulfillment</th>
              <th>Total Amount</th>
              <th>Payment</th>
              <th>Order Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($onlineOrders as $o): ?>
              <tr>
                <td>
                  <strong>#ORD-<?= (int)$o['order_id'] ?></strong>
                  <div class="caption">Order #<?= (int)$o['order_id'] ?></div>
                </td>
                <td>
                  <?= e(date('M j, Y', strtotime((string)$o['order_date']))) ?>
                  <div class="caption"><?= e(date('g:i:s A', strtotime((string)$o['order_date']))) ?></div>
                </td>
                <td>
                  <strong><?= e($o['customer_name']) ?></strong>
                  <?php if (!empty($o['customer_phone'])): ?>
                    <div class="caption"><?= e($o['customer_phone']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge secondary"><?= e(ucfirst((string)$o['fulfillment_method'])) ?></span></td>
                <td><strong><?= peso((float)$o['total_amount']) ?></strong></td>
                <td>
                  <span class="badge secondary"><?= e($o['payment_method'] ?? 'COD') ?></span>
                  <div class="caption"><?= e(ucfirst((string)($o['payment_status'] ?? 'Completed'))) ?></div>
                </td>
                <td>
                  <span class="badge <?= in_array($o['order_status'], ['completed', 'confirmed'], true) ? 'badge-success' : 'badge-warning' ?>">
                    <?= e(ucwords(str_replace('_', ' ', (string)$o['order_status']))) ?>
                  </span>
                </td>
                <td>
                  <button type="button" class="btn secondary btn-sm" data-modal-open="online-modal-<?= (int)$o['order_id'] ?>">
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

  <!-- Online Order Details Modals -->
  <?php foreach ($onlineOrders as $o): ?>
    <dialog class="modal modal-wide" id="online-modal-<?= (int)$o['order_id'] ?>" aria-labelledby="online-title-<?= (int)$o['order_id'] ?>" <?= (!empty($viewId) && (int)$viewId === (int)$o['order_id']) ? 'data-open-on-load="true"' : '' ?>>
      <div class="modal-head">
        <div>
          <span class="badge" style="background: #fef3c7; color: #92400e; margin-bottom: 0.25rem;">Online Order</span>
          <h3 id="online-title-<?= (int)$o['order_id'] ?>" style="margin: 0;">Order #ORD-<?= (int)$o['order_id'] ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times; Close</button>
      </div>
      <div class="modal-body">
        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Transaction / Order Reference</p>
            <strong>ONLINE-ORD-<?= (int)$o['order_id'] ?></strong>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Date &amp; Time</p>
            <div><?= e(date('F j, Y · g:i:s A', strtotime((string)$o['order_date']))) ?></div>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Receipt Reference</p>
            <code>REC-ORD-<?= (int)$o['order_id'] ?></code>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Order Status</p>
            <div>
              <span class="badge <?= in_array($o['order_status'], ['completed', 'confirmed'], true) ? 'badge-success' : 'badge-warning' ?>">
                <?= e(ucwords(str_replace('_', ' ', (string)$o['order_status']))) ?>
              </span>
            </div>
          </div>
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Customer Information</p>
            <strong><?= e($o['customer_name']) ?></strong>
            <div class="caption"><?= e($o['customer_email'] ?? '') ?> · <?= e($o['customer_phone'] ?? '') ?></div>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Fulfillment Information</p>
            <div><strong><?= e(ucfirst((string)$o['fulfillment_method'])) ?></strong></div>
            <?php if ($o['fulfillment_method'] === 'delivery'): ?>
              <div class="caption" style="margin-top: 0.25rem;">
                <?= e($o['delivery_address_line1'] ?? '') ?>
                <?= !empty($o['delivery_address_line2']) ? ', ' . e($o['delivery_address_line2']) : '' ?>
                <?= !empty($o['delivery_city']) ? ', ' . e($o['delivery_city']) : '' ?>
                <?= !empty($o['delivery_province']) ? ', ' . e($o['delivery_province']) : '' ?>
              </div>
            <?php else: ?>
              <div class="caption">Customer In-store Counter Pickup</div>
            <?php endif; ?>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Payment Method &amp; Status</p>
            <div>
              <span class="badge secondary"><?= e($o['payment_method'] ?? 'COD') ?></span>
              <span class="badge badge-success"><?= e(ucfirst((string)($o['payment_status'] ?? 'Completed'))) ?></span>
            </div>
          </div>
        </div>

        <h4 style="margin: 1rem 0 0.5rem 0;">Purchased Products</h4>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Unit Price</th>
                <th>Qty</th>
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
              <strong>Final Total:</strong>
              <strong style="font-size: 1.15rem; color: var(--primary, #0284c7);"><?= peso((float)$o['total_amount']) ?></strong>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
      </div>
    </dialog>
  <?php endforeach; ?>

<?php else: ?>
  <!-- 3. REFUND REQUESTS VIEW -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Customer Refund Requests</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Customer-submitted return and refund claims. Pending requests are prioritized for manager review.</p>
      </div>
      <span class="caption"><?= count($refundRequests) ?> records</span>
    </div>

    <?php if (empty($refundRequests)): ?>
      <div class="empty">No refund requests found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?><?= $stFilter !== '' ? ' with status ' . e($stFilter) : '' ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Refund Ref</th>
              <th>Original Transaction</th>
              <th>Customer</th>
              <th>Request Date &amp; Time</th>
              <th>Requested Amount</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($refundRequests as $rf): ?>
              <tr>
                <td>
                  <strong><?= e($rf['refund_reference']) ?></strong>
                  <div class="caption">ID #<?= (int)$rf['refund_request_id'] ?></div>
                </td>
                <td>
                  <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($rf['channel'] === 'online' ? 'online' : 'walkin') ?>&view=<?= (int)($rf['order_id'] ?: $rf['sale_id']) ?>">
                    <strong><?= e($rf['transaction_ref']) ?></strong>
                  </a>
                  <div>
                    <span class="badge secondary" style="font-size: 0.72rem;"><?= e($rf['channel_label']) ?></span>
                  </div>
                </td>
                <td>
                  <strong><?= e($rf['customer_name']) ?></strong>
                  <?php if (!empty($rf['customer_phone'])): ?>
                    <div class="caption"><?= e($rf['customer_phone']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= e(date('M j, Y', strtotime((string)$rf['created_at']))) ?>
                  <div class="caption"><?= e(date('g:i A', strtotime((string)$rf['created_at']))) ?></div>
                </td>
                <td>
                  <strong style="color: var(--danger, #dc2626); font-size: 0.95rem;"><?= peso((float)$rf['requested_amount']) ?></strong>
                  <?php if ($rf['request_status'] === 'approved' && !empty($rf['approved_amount'])): ?>
                    <div class="caption" style="color: #059669;">Approved: <?= peso((float)$rf['approved_amount']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($rf['request_status'] === 'pending'): ?>
                    <span class="badge badge-warning" style="font-weight: 600;">Pending Review</span>
                  <?php elseif ($rf['request_status'] === 'approved'): ?>
                    <span class="badge badge-success" style="font-weight: 600;">Approved</span>
                  <?php elseif ($rf['request_status'] === 'rejected'): ?>
                    <span class="badge badge-danger" style="font-weight: 600;">Rejected</span>
                  <?php else: ?>
                    <span class="badge secondary"><?= e(ucfirst((string)$rf['request_status'])) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <button type="button" class="btn secondary btn-sm" data-modal-open="refund-modal-<?= (int)$rf['refund_request_id'] ?>">
                    <?= $rf['request_status'] === 'pending' ? 'Review Request' : 'View Details' ?>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- Refund Request Details Modals & Action Dialogs -->
  <?php foreach ($refundRequests as $rf): ?>
    <dialog class="modal modal-wide" id="refund-modal-<?= (int)$rf['refund_request_id'] ?>" aria-labelledby="refund-title-<?= (int)$rf['refund_request_id'] ?>" <?= (!empty($viewId) && (int)$viewId === (int)$rf['refund_request_id']) ? 'data-open-on-load="true"' : '' ?>>
      <div class="modal-head">
        <div>
          <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.35rem;">
            <span class="badge" style="background: var(--brand-soft, #e6f4f0); color: var(--brand, #0d6e57); font-weight: 600;">Refund Request</span>
            <?php if ($rf['request_status'] === 'pending'): ?>
              <span class="badge badge-warning">Pending Review</span>
            <?php elseif ($rf['request_status'] === 'approved'): ?>
              <span class="badge badge-success">Approved</span>
            <?php elseif ($rf['request_status'] === 'rejected'): ?>
              <span class="badge badge-danger">Rejected</span>
            <?php endif; ?>
          </div>
          <h3 id="refund-title-<?= (int)$rf['refund_request_id'] ?>" style="margin: 0;">Request <?= e($rf['refund_reference']) ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times; Close</button>
      </div>

      <div class="modal-body">
        <!-- 2-Column Summary Cards -->
        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1.5rem; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Refund Reference</p>
            <strong style="font-size: 1rem;"><?= e($rf['refund_reference']) ?></strong>
            
            <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Request Date &amp; Time</p>
            <div><?= e(date('F j, Y · g:i:s A', strtotime((string)$rf['created_at']))) ?></div>

            <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Requested Refund Amount</p>
            <strong style="font-size: 1.15rem; color: var(--danger, #dc2626);"><?= peso((float)$rf['requested_amount']) ?></strong>

            <?php if ($rf['request_status'] === 'approved' && !empty($rf['approved_amount'])): ?>
              <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Approved Refund Amount</p>
              <strong style="font-size: 1.1rem; color: #059669;"><?= peso((float)$rf['approved_amount']) ?></strong>
            <?php endif; ?>
          </div>

          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Original Transaction</p>
            <div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
              <strong><?= e($rf['transaction_ref']) ?></strong>
              <span class="badge secondary" style="font-size: 0.75rem;"><?= e($rf['channel_label']) ?></span>
              <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($rf['channel'] === 'online' ? 'online' : 'walkin') ?>&view=<?= (int)($rf['order_id'] ?: $rf['sale_id']) ?>"
                 class="btn secondary btn-sm"
                 style="padding: 0.15rem 0.5rem; font-size: 0.75rem;">
                View Original Transaction &rarr;
              </a>
            </div>

            <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Original Transaction Total</p>
            <div><strong><?= peso((float)($rf['original_total_amount'] ?? 0)) ?></strong> (dated <?= e(date('M j, Y g:i A', strtotime((string)($rf['original_transaction_date'] ?? $rf['created_at'])))) ?>)</div>

            <p style="margin: 0.6rem 0 0.25rem 0;" class="caption">Customer Information</p>
            <strong><?= e($rf['customer_name']) ?></strong>
            <div class="caption"><?= e($rf['customer_email'] ?? '') ?><?= !empty($rf['customer_phone']) ? ' · ' . e($rf['customer_phone']) : '' ?></div>
          </div>
        </div>

        <!-- Customer Reason for Request -->
        <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1rem;">
          <p class="caption" style="margin: 0 0 0.35rem 0; font-weight: 700; color: #b45309; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em;">
            Customer Reason for Refund Request
          </p>
          <div style="color: #78350f; font-size: 0.95rem; line-height: 1.5;"><?= nl2br(e($rf['customer_reason'])) ?></div>
        </div>

        <!-- Supporting Evidence -->
        <?php if (!empty($rf['supporting_evidence'])): ?>
          <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 0.85rem 1rem; border-radius: 4px; margin-bottom: 1.25rem;">
            <p class="caption" style="margin: 0 0 0.35rem 0; font-weight: 700; color: #047857; text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.05em;">
              Supporting Evidence / Customer Verification
            </p>
            <div style="color: #064e3b; font-size: 0.92rem; line-height: 1.5;"><?= nl2br(e($rf['supporting_evidence'])) ?></div>
          </div>
        <?php endif; ?>

        <!-- Purchased Products in Original Transaction -->
        <h4 style="margin: 1.25rem 0 0.5rem 0;">Purchased Products in Original Transaction</h4>
        <div class="table-wrap" style="margin-bottom: 1.5rem;">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Unit Price</th>
                <th>Qty Purchased</th>
                <th style="text-align: right;">Line Total</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rf['items'])): ?>
                <tr>
                  <td colspan="5" class="caption" style="text-align: center; padding: 1rem;">Original items details preserved in transaction reference <?= e($rf['transaction_ref']) ?>.</td>
                </tr>
              <?php else: ?>
                <?php
                $itemSubtotal = 0.0;
                foreach ($rf['items'] as $it):
                  $lTot = (float)$it['unit_price'] * (int)$it['quantity'];
                  $itemSubtotal += $lTot;
                ?>
                  <tr>
                    <td><strong><?= e($it['product_name']) ?></strong></td>
                    <td><code><?= e($it['sku'] ?: '—') ?></code></td>
                    <td><?= peso((float)$it['unit_price']) ?></td>
                    <td><?= (int)$it['quantity'] ?></td>
                    <td style="text-align: right;"><?= peso($lTot) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Previous Decision / Processing History -->
        <?php if ($rf['request_status'] === 'approved'): ?>
          <div style="background: #ecfdf5; border: 1px solid #a7f3d0; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
              <span class="badge badge-success">Decision: Approved</span>
              <strong style="color: #065f46;">Approved Amount: <?= peso((float)$rf['approved_amount']) ?></strong>
            </div>
            <div class="caption" style="color: #047857;">
              Decided by <strong><?= e($rf['decided_by_name'] ?: 'Authorized Pet Store Manager') ?></strong>
              <?php if (!empty($rf['decided_by_position'])): ?>
                (<?= e($rf['decided_by_position']) ?>)
              <?php endif; ?>
              · <?= e(date('F j, Y · g:i A', strtotime((string)$rf['decided_at']))) ?>
            </div>
            <p class="caption" style="margin: 0.5rem 0 0 0; color: #065f46;">
              Operational Note: Payment reversal and inventory inspection follow their sanctioned separate procedures. Stock is not automatically modified.
            </p>
          </div>
        <?php elseif ($rf['request_status'] === 'rejected'): ?>
          <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
            <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
              <span class="badge badge-danger">Decision: Rejected</span>
            </div>
            <div class="caption" style="color: #991b1b;">
              Decided by <strong><?= e($rf['decided_by_name'] ?: 'Authorized Pet Store Manager') ?></strong>
              <?php if (!empty($rf['decided_by_position'])): ?>
                (<?= e($rf['decided_by_position']) ?>)
              <?php endif; ?>
              · <?= e(date('F j, Y · g:i A', strtotime((string)$rf['decided_at']))) ?>
            </div>
            <div style="margin-top: 0.5rem; font-size: 0.92rem; color: #7f1d1d;">
              <strong>Rejection Reason:</strong> <?= nl2br(e($rf['rejection_reason'])) ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="modal-foot" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
          <?php if ($rf['request_status'] === 'pending'): ?>
            <div class="row" style="gap: 0.5rem;">
              <button type="button" class="btn btn-primary btn-sm" data-modal-open="approve-modal-<?= (int)$rf['refund_request_id'] ?>">
                Approve Request...
              </button>
              <button type="button" class="btn secondary btn-sm" style="color: var(--danger, #dc2626); border-color: rgba(220, 38, 38, 0.35);" data-modal-open="reject-modal-<?= (int)$rf['refund_request_id'] ?>">
                Reject Request...
              </button>
            </div>
          <?php endif; ?>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close>Close</button>
      </div>
    </dialog>

    <?php if ($rf['request_status'] === 'pending'): ?>
      <!-- Application-Styled Approve Confirmation Dialog -->
      <dialog class="modal" id="approve-modal-<?= (int)$rf['refund_request_id'] ?>" style="max-width: 520px;">
        <div class="modal-head">
          <h3 style="margin: 0; color: #065f46;">Confirm Refund Approval</h3>
          <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
        </div>
        <form method="post" action="/manager<?= !empty($isSuper) ? '/units/' . (int)$unitId : '' ?>/sales/refunds/<?= (int)$rf['refund_request_id'] ?>/approve">
          <?= \App\Core\CSRF::field() ?>
          <div class="modal-body">
            <p style="margin: 0 0 1rem 0;">Are you sure you want to approve this customer refund request?</p>

            <div style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem; border: 1px solid var(--border, #e5e7eb);">
              <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
                <span class="caption">Refund Reference:</span>
                <strong><?= e($rf['refund_reference']) ?></strong>
              </div>
              <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
                <span class="caption">Customer:</span>
                <span><?= e($rf['customer_name']) ?></span>
              </div>
              <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
                <span class="caption">Original Transaction:</span>
                <span><?= e($rf['transaction_ref']) ?> (<?= e($rf['channel_label']) ?>)</span>
              </div>
              <hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid var(--border, #e5e7eb);">
              <div class="row" style="justify-content: space-between;">
                <strong>Refund Amount to Approve:</strong>
                <strong style="color: #059669; font-size: 1.15rem;"><?= peso((float)$rf['requested_amount']) ?></strong>
              </div>
            </div>

            <div style="background: #f0fdf4; border-left: 3px solid #10b981; padding: 0.65rem 0.85rem; border-radius: 4px; font-size: 0.82rem; color: #065f46; margin-bottom: 0.5rem;">
              <strong>Policy &amp; Workflow Notice:</strong> Approving this request records manager authorization and the decision timestamp. Original transaction records are preserved. Payment reversal and stock inspection proceed through separate operations.
            </div>
          </div>
          <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary btn-sm">Confirm Approval</button>
          </div>
        </form>
      </dialog>

      <!-- Application-Styled Reject Confirmation Dialog -->
      <dialog class="modal" id="reject-modal-<?= (int)$rf['refund_request_id'] ?>" style="max-width: 520px;">
        <div class="modal-head">
          <h3 style="margin: 0; color: #991b1b;">Reject Refund Request</h3>
          <button type="button" class="btn ghost btn-sm" data-modal-close>&times;</button>
        </div>
        <form method="post" action="/manager<?= !empty($isSuper) ? '/units/' . (int)$unitId : '' ?>/sales/refunds/<?= (int)$rf['refund_request_id'] ?>/reject">
          <?= \App\Core\CSRF::field() ?>
          <div class="modal-body">
            <p style="margin: 0 0 1rem 0;">Are you sure you want to reject this customer refund request? Please state the reason for rejection.</p>

            <div style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem; border-radius: 6px; margin-bottom: 1rem; border: 1px solid var(--border, #e5e7eb);">
              <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
                <span class="caption">Refund Reference:</span>
                <strong><?= e($rf['refund_reference']) ?></strong>
              </div>
              <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
                <span class="caption">Customer:</span>
                <span><?= e($rf['customer_name']) ?></span>
              </div>
              <div class="row" style="justify-content: space-between;">
                <span class="caption">Requested Amount:</span>
                <strong style="color: var(--danger, #dc2626);"><?= peso((float)$rf['requested_amount']) ?></strong>
              </div>
            </div>

            <div style="margin-bottom: 1rem;">
              <label for="rej-reason-<?= (int)$rf['refund_request_id'] ?>" style="display: block; font-weight: 600; font-size: 0.85rem; margin-bottom: 0.35rem;">
                Rejection Reason <span style="color: var(--danger, #dc2626);">*</span>
              </label>
              <textarea id="rej-reason-<?= (int)$rf['refund_request_id'] ?>"
                        name="rejection_reason"
                        required
                        rows="3"
                        style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #d1d5db); font-family: inherit; font-size: 0.875rem;"
                        placeholder="State clearly why this refund request was not approved (e.g. past policy window, non-refundable product)..."></textarea>
            </div>

            <div class="caption" style="color: var(--muted, #6b7280);">
              This decision and your reason will be recorded in the audit trail and finalized. Original transactions remain intact.
            </div>
          </div>
          <div class="modal-foot" style="display: flex; justify-content: flex-end; gap: 0.5rem;">
            <button type="button" class="btn ghost btn-sm" data-modal-close>Cancel</button>
            <button type="submit" class="btn secondary btn-sm" style="background: var(--danger, #dc2626); color: white; border-color: var(--danger, #dc2626);">
              Confirm Rejection
            </button>
          </div>
        </form>
      </dialog>
    <?php endif; ?>
  <?php endforeach; ?>
<?php endif; ?>
