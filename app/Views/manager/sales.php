<?php
require __DIR__ . '/_nav.php';
$activeSalesTab = $salesTab ?? 'walkin';
$q = $searchQuery ?? '';
$walkInSales = $walkInSales ?? [];
$onlineDeliveryOrders = $onlineDeliveryOrders ?? [];
$onlinePickupOrders = $onlinePickupOrders ?? [];
$baseQuerySep = !empty($unitQuery) ? '&' : '?';

$orderStatusBadgeClass = static function (string $st): string {
    if (in_array($st, ['completed', 'confirmed'], true)) {
        return 'badge-success';
    }
    if (in_array($st, ['ready_for_pickup', 'out_for_delivery', 'processing'], true)) {
        return 'badge-info';
    }
    if (in_array($st, ['cancelled', 'delivery_failed'], true)) {
        return 'badge-danger';
    }
    return 'badge-warning';
};

$paymentStatusBadgeClass = static function (string $pStatus): string {
    $pStatus = strtolower($pStatus);
    if ($pStatus === 'completed') {
        return 'badge-success';
    }
    if ($pStatus === 'refunded') {
        return 'badge-danger';
    }
    return 'badge-warning';
};

$formatPaymentStatus = static function (string $pStatus): string {
    return ucwords(str_replace('_', ' ', strtolower(trim($pStatus))));
};

$renderRefundNotice = static function (array $tx): string {
    $scope = (string)($tx['refund_scope'] ?? 'none');
    if ($scope === 'none') {
        return '';
    }
    $refundedAmt = (float)($tx['refunded_amount'] ?? 0.0);
    $paidAmt = (float)($tx['paid_amount'] ?? 0.0);

    if ($scope === 'partial') {
        $badgeClass = 'badge-warning';
        $badgeText = 'Partial Refund Recorded';
        $headline = peso($refundedAmt) . ' refunded of ' . peso($paidAmt) . ' paid';
        $detail = 'Refunds are recorded at the transaction level; item-level refund attribution is not stored in the current schema, so individual product rows are not marked.';
        $boxBg = '#fffbeb';
        $boxBorder = '#f59e0b';
    } elseif ($scope === 'full') {
        $badgeClass = 'badge-danger';
        $badgeText = 'Full Refund Recorded';
        $headline = peso($refundedAmt) . ' refunded of ' . peso($paidAmt) . ' paid';
        $detail = 'Refunds are recorded at the transaction level; item-level refund attribution is not stored in the current schema, so individual product rows are not marked.';
        $boxBg = '#fef2f2';
        $boxBorder = '#ef4444';
    } else {
        $badgeClass = 'badge-danger';
        $badgeText = 'Refund Recorded';
        if (!empty($tx['refund_inconsistent'])) {
            $headline = peso($refundedAmt) . ' refunded vs. ' . peso($paidAmt) . ' paid (inconsistent amount)';
            $detail = 'Recorded refund total exceeds the original paid amount at the transaction level; verify the underlying payment records. Item-level refund attribution is not stored in the current schema, so individual product rows are not marked.';
        } else {
            $headline = 'Transaction-level refund recorded';
            $detail = 'This transaction payment is marked refunded without a separate refund amount or item-level breakdown in the current schema, so partial vs. full distinction and individual product attribution are unavailable.';
        }
        $boxBg = '#fef2f2';
        $boxBorder = '#ef4444';
    }

    return '<div class="refund-notice" style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 6px; background: ' . $boxBg . '; border-left: 4px solid ' . $boxBorder . ';">'
        . '<div style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">'
        . '<span class="badge ' . $badgeClass . '">' . e($badgeText) . '</span>'
        . '<strong>' . e($headline) . '</strong>'
        . '</div>'
        . '<p class="caption" style="margin: 0.4rem 0 0 0;">' . e($detail) . '</p>'
        . '</div>';
};
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Transaction Management</p>
    <h1>Sales</h1>
    <p class="muted">Successful customer transactions organized across Walk-in, Online — Delivery, and Online — Pick-up channels.</p>
  </div>
</div>

<!-- Segmented Control for Sales Categories -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
  <div class="segmented-bar" role="tablist" aria-label="Sales channel selector">
    <a href="/manager/sales<?= $unitQuery ?><?= $baseQuerySep ?>tab=walkin"
       class="segmented-pill <?= $activeSalesTab === 'walkin' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'walkin' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'walkin' ? 'var(--brand-soft, #e6f4f0)' : 'var(--bg-subtle, #f3f4f6)' ?>; color: <?= $activeSalesTab === 'walkin' ? 'var(--brand, #0d6e57)' : 'var(--muted, #6b7280)' ?>; border: 1px solid <?= $activeSalesTab === 'walkin' ? 'rgba(16, 185, 129, 0.35)' : 'var(--border, #e5e7eb)' ?>; font-size: 0.75rem; font-weight: 600;">Walk-in</span>
      Walk-in (<?= count($walkInSales) ?>)
    </a>
    <a href="/manager/sales<?= $unitQuery ?><?= $baseQuerySep ?>tab=delivery"
       class="segmented-pill <?= $activeSalesTab === 'delivery' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'delivery' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'delivery' ? 'var(--brand-soft, #e6f4f0)' : 'var(--bg-subtle, #f3f4f6)' ?>; color: <?= $activeSalesTab === 'delivery' ? 'var(--brand, #0d6e57)' : 'var(--muted, #6b7280)' ?>; border: 1px solid <?= $activeSalesTab === 'delivery' ? 'rgba(16, 185, 129, 0.35)' : 'var(--border, #e5e7eb)' ?>; font-size: 0.75rem; font-weight: 600;">Delivery</span>
      Online — Delivery (<?= count($onlineDeliveryOrders) ?>)
    </a>
    <a href="/manager/sales<?= $unitQuery ?><?= $baseQuerySep ?>tab=pickup"
       class="segmented-pill <?= $activeSalesTab === 'pickup' ? 'is-active' : '' ?>"
       role="tab"
       aria-selected="<?= $activeSalesTab === 'pickup' ? 'true' : 'false' ?>">
      <span class="badge" style="background: <?= $activeSalesTab === 'pickup' ? 'var(--brand-soft, #e6f4f0)' : 'var(--bg-subtle, #f3f4f6)' ?>; color: <?= $activeSalesTab === 'pickup' ? 'var(--brand, #0d6e57)' : 'var(--muted, #6b7280)' ?>; border: 1px solid <?= $activeSalesTab === 'pickup' ? 'rgba(16, 185, 129, 0.35)' : 'var(--border, #e5e7eb)' ?>; font-size: 0.75rem; font-weight: 600;">Pick-up</span>
      Online — Pick-up (<?= count($onlinePickupOrders) ?>)
    </a>
  </div>

  <!-- Search Controls -->
  <form method="get" action="/manager/sales" class="row" style="gap: 0.5rem; align-items: center;">
    <?php if (!empty($isSuper)): ?>
      <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
    <?php endif; ?>
    <input type="hidden" name="tab" value="<?= e($activeSalesTab) ?>">

    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search reference, customer... (Press Enter)" style="width: 260px; padding: 0.4rem 0.75rem; border-radius: 6px; border: 1px solid var(--border, #d1d5db); font-size: 0.85rem;">
    <?php if ($q !== ''): ?>
      <a href="/manager/sales<?= $unitQuery ?><?= $baseQuerySep ?>tab=<?= e($activeSalesTab) ?>" class="btn ghost btn-sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($activeSalesTab === 'walkin'): ?>
  <!-- 1. WALK-IN SALES VIEW -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Walk-in Sales Transactions</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Successful in-store cashier transactions sorted by date and time (newest first).</p>
      </div>
      <span class="caption"><?= count($walkInSales) ?> records</span>
    </div>

    <?php if (empty($walkInSales)): ?>
      <div class="empty">No successful walk-in transactions found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Receipt Ref</th>
              <th>Date &amp; Time</th>
              <th>Customer</th>
              <th>Fulfillment</th>
              <th>Items</th>
              <th>Total Amount</th>
              <th>Payment</th>
              <th>Sale Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($walkInSales as $s):
              $pStat = (string)($s['payment_status'] ?? 'completed');
            ?>
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
                <td><span class="badge secondary">Walk-in</span></td>
                <td><?= (int)$s['item_count'] ?> item(s)</td>
                <td><strong><?= peso((float)$s['total_amount']) ?></strong></td>
                <td>
                  <span class="badge secondary"><?= e($s['payment_method'] ?? 'Cash') ?></span>
                  <div style="margin-top: 0.2rem;">
                    <span class="badge <?= $paymentStatusBadgeClass($pStat) ?>" style="font-size: 0.72rem;"><?= e($formatPaymentStatus($pStat)) ?></span>
                  </div>
                </td>
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
  <?php foreach ($walkInSales as $s):
    $pStat = (string)($s['payment_status'] ?? 'completed');
  ?>
    <dialog class="modal modal-wide" id="walkin-modal-<?= (int)$s['sale_id'] ?>" aria-labelledby="walkin-title-<?= (int)$s['sale_id'] ?>" <?= (!empty($viewId) && (int)$viewId === (int)$s['sale_id']) ? 'data-open-on-load="true"' : '' ?>>
      <div class="modal-head">
        <div>
          <span class="badge" style="background: #e0f2fe; color: #0369a1; margin-bottom: 0.25rem;">Walk-in Sale</span>
          <h3 id="walkin-title-<?= (int)$s['sale_id'] ?>" style="margin: 0;">Transaction #REC-S-<?= (int)$s['sale_id'] ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
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
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Sale Status</p>
            <div>
              <span class="badge <?= $s['sale_status'] === 'completed' ? 'badge-success' : 'badge-danger' ?>">
                <?= e(ucfirst((string)$s['sale_status'])) ?>
              </span>
            </div>
          </div>
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Customer Information</p>
            <strong><?= e($s['customer_name']) ?></strong>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Fulfillment Channel</p>
            <div>In-Store Walk-in Counter Sale</div>
            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Payment Method &amp; Status</p>
            <div>
              <span class="badge secondary"><?= e($s['payment_method'] ?? 'Cash (In-store)') ?></span>
              <span class="badge <?= $paymentStatusBadgeClass($pStat) ?>"><?= e($formatPaymentStatus($pStat)) ?></span>
            </div>
          </div>
        </div>

        <?= $renderRefundNotice($s) ?>

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

<?php else:
  $isDeliveryChannel = $activeSalesTab === 'delivery';
  $ordersList = $isDeliveryChannel ? $onlineDeliveryOrders : $onlinePickupOrders;
  $channelHeading = $isDeliveryChannel ? 'Online — Delivery Transactions' : 'Online — Pick-up Transactions';
  $channelDesc = $isDeliveryChannel
      ? 'Customer delivery orders with successful payments (payment status, fulfillment, and order lifecycle status tracked independently).'
      : 'Customer in-store pickup orders with successful payments (payment status, fulfillment, and order lifecycle status tracked independently).';
  $channelLabel = $isDeliveryChannel ? 'Delivery' : 'Pick-up';
?>
  <!-- 2 & 3. ONLINE DELIVERY / ONLINE PICK-UP SALES VIEW -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;"><?= e($channelHeading) ?></h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;"><?= e($channelDesc) ?></p>
      </div>
      <span class="caption"><?= count($ordersList) ?> records</span>
    </div>

    <?php if (empty($ordersList)): ?>
      <div class="empty">No successful <?= e(strtolower($channelLabel)) ?> transactions found<?= $q !== '' ? ' matching "' . e($q) . '"' : '' ?>.</div>
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
            <?php foreach ($ordersList as $o):
              $pStat = (string)($o['payment_status'] ?? 'completed');
              $oStat = (string)$o['order_status'];
            ?>
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
                <td><span class="badge secondary"><?= e($channelLabel) ?></span></td>
                <td><strong><?= peso((float)$o['total_amount']) ?></strong></td>
                <td>
                  <span class="badge secondary"><?= e($o['payment_method'] ?? 'COD') ?></span>
                  <div style="margin-top: 0.2rem;">
                    <span class="badge <?= $paymentStatusBadgeClass($pStat) ?>" style="font-size: 0.72rem;"><?= e($formatPaymentStatus($pStat)) ?></span>
                  </div>
                </td>
                <td>
                  <span class="badge <?= $orderStatusBadgeClass($oStat) ?>">
                    <?= e(ucwords(str_replace('_', ' ', $oStat))) ?>
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
  <?php foreach ($ordersList as $o):
    $pStat = (string)($o['payment_status'] ?? 'completed');
    $oStat = (string)$o['order_status'];
    $orderTotalAmt = (float)$o['total_amount'];
  ?>
    <dialog class="modal modal-wide" id="online-modal-<?= (int)$o['order_id'] ?>" aria-labelledby="online-title-<?= (int)$o['order_id'] ?>" <?= (!empty($viewId) && (int)$viewId === (int)$o['order_id']) ? 'data-open-on-load="true"' : '' ?>>
      <div class="modal-head">
        <div>
          <span class="badge" style="background: #fef3c7; color: #92400e; margin-bottom: 0.25rem;">Online — <?= e($channelLabel) ?></span>
          <h3 id="online-title-<?= (int)$o['order_id'] ?>" style="margin: 0;">Order #ORD-<?= (int)$o['order_id'] ?></h3>
        </div>
        <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
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
              <span class="badge <?= $orderStatusBadgeClass($oStat) ?>">
                <?= e(ucwords(str_replace('_', ' ', $oStat))) ?>
              </span>
            </div>
          </div>
          <div>
            <p style="margin: 0 0 0.25rem 0;" class="caption">Customer Information</p>
            <strong><?= e($o['customer_name']) ?></strong>
            <div class="caption"><?= e($o['customer_email'] ?? '') ?><?= !empty($o['customer_phone']) ? ' · ' . e($o['customer_phone']) : '' ?></div>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Fulfillment Information</p>
            <div><strong><?= e($channelLabel) ?></strong></div>
            <?php if ($o['fulfillment_method'] === 'delivery'): ?>
              <div class="caption" style="margin-top: 0.25rem;">
                <?= e($o['delivery_address_line1'] ?? '') ?>
                <?= !empty($o['delivery_address_line2']) ? ', ' . e($o['delivery_address_line2']) : '' ?>
                <?= !empty($o['delivery_city']) ? ', ' . e($o['delivery_city']) : '' ?>
                <?= !empty($o['delivery_province']) ? ', ' . e($o['delivery_province']) : '' ?>
              </div>
              <?php if (!empty($o['delivery_employee_name'])): ?>
                <div class="caption" style="margin-top: 0.25rem;">Courier: <?= e($o['delivery_employee_name']) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <div class="caption">Customer In-store Counter Pickup</div>
            <?php endif; ?>

            <p style="margin: 0.5rem 0 0.25rem 0;" class="caption">Payment Method &amp; Status</p>
            <div>
              <span class="badge secondary"><?= e($o['payment_method'] ?? 'COD') ?></span>
              <span class="badge <?= $paymentStatusBadgeClass($pStat) ?>"><?= e($formatPaymentStatus($pStat)) ?></span>
            </div>
          </div>
        </div>

        <?= $renderRefundNotice($o) ?>

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

        <?php
        $mFee = (float)($o['delivery_fee'] ?? 0.0);
        $mSub = isset($o['subtotal_amount']) && $o['subtotal_amount'] !== null
            ? (float)$o['subtotal_amount']
            : ($calcSubtotal > 0 ? $calcSubtotal : max(0.0, $orderTotalAmt - $mFee));
        ?>
        <div style="display: flex; justify-content: flex-end;">
          <div style="min-width: 240px; background: var(--bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px;">
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Subtotal:</span>
              <span><?= peso($mSub) ?></span>
            </div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Delivery Fee:</span>
              <span><?= peso($mFee) ?></span>
            </div>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
              <span class="caption">Discount:</span>
              <span><?= peso(0.0) ?></span>
            </div>
            <hr style="margin: 0.5rem 0; border: 0; border-top: 1px solid var(--border, #e5e7eb);">
            <div class="row" style="justify-content: space-between;">
              <strong>Final Total:</strong>
              <strong style="font-size: 1.15rem; color: var(--primary, #0284c7);"><?= peso($orderTotalAmt) ?></strong>
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
