<?php
require __DIR__ . '/_nav.php';
$activeSalesTab = $salesTab ?? 'walkin';
$q = $searchQuery ?? '';
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Transaction Management</p>
    <h1>Sales</h1>
    <p class="muted">Complete transaction histories for Pet Store walk-in sales and online orders.</p>
  </div>
</div>

<!-- Switch-like Segmented Control for Sales Channel -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
  <div class="segmented-bar" role="tablist" aria-label="Sales channel selector">
    <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=walkin"
       class="segmented-pill <?= $activeSalesTab === 'walkin' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'walkin' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'walkin' ? 'var(--primary, #0284c7)' : '#e0f2fe' ?>; color: <?= $activeSalesTab === 'walkin' ? '#fff' : '#0369a1' ?>; font-size: 0.75rem;">Walk-in</span>
      Walk-in Sales (<?= count($walkInSales) ?>)
    </a>
    <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=online"
       class="segmented-pill <?= $activeSalesTab === 'online' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'online' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'online' ? '#b45309' : '#fef3c7' ?>; color: <?= $activeSalesTab === 'online' ? '#fff' : '#92400e' ?>; font-size: 0.75rem;">Online</span>
      Online Orders (<?= count($onlineOrders) ?>)
    </a>
  </div>

  <!-- Search & Filter Form -->
  <form method="get" action="/manager/sales" class="row" style="gap: 0.5rem; align-items: center;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>
    <input type="hidden" name="tab" value="<?= e($activeSalesTab) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search reference, customer..." style="width: 240px; padding: 0.4rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #d1d5db);">
    <button type="submit" class="btn secondary btn-sm">Filter</button>
    <?php if ($q !== ''): ?>
      <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($activeSalesTab) ?>" class="btn ghost btn-sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($activeSalesTab === 'walkin'): ?>
  <!-- WALK-IN SALES VIEW -->
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

<?php else: ?>
  <!-- ONLINE ORDERS VIEW -->
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
<?php endif; ?>
