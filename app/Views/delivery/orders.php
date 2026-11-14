<?php
$title = $title ?? 'Delivery Orders · Pet Store';
$activeTab = $activeTab ?? 'queue';
$q = $searchQuery ?? '';
$isCourier = !empty($isCourier);
$todayDate = $todayDate ?? date('Y-m-d');

$tabs = [
    'queue' => 'Dispatch Queue',
    'active' => 'My Deliveries',
    'history' => 'Delivery History',
];

$buildTabUrl = function (string $t) use ($q): string {
    $params = ['tab' => $t];
    if ($q !== '') {
        $params['q'] = $q;
    }
    return '/delivery/orders?' . http_build_query($params);
};
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Pet Store Delivery Portal</p>
    <h1>Delivery Orders</h1>
    <p class="muted">Claim available Pet Store delivery orders, track active deliveries, and record delivery outcomes.</p>
  </div>
</div>

<?php if (!$isCourier): ?>
  <div class="alert alert-info" style="margin-bottom: 1.25rem;">
    <strong>Administrative Oversight Mode (Read-Only):</strong> Operational courier actions (dispatching, completing, or reporting failures) require an active Pet Store <code>DELIVERY_PERSONNEL</code> role assignment.
  </div>
<?php endif; ?>

<nav class="tabs" style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; border-bottom: 1px solid var(--color-border, #e5e7eb);">
  <?php foreach ($tabs as $tabKey => $tabLabel): ?>
    <a href="<?= e($buildTabUrl($tabKey)) ?>"
       class="tab-link <?= $activeTab === $tabKey ? 'is-active' : '' ?>"
       style="padding: 0.65rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === $tabKey ? 'var(--color-primary, #047857)' : 'transparent' ?>; color: <?= $activeTab === $tabKey ? 'var(--color-primary, #047857)' : 'var(--color-muted, #6b7280)' ?>;">
      <?= e($tabLabel) ?>
    </a>
  <?php endforeach; ?>
</nav>

<section class="card" style="margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <form method="get" action="/delivery/orders" class="row" style="gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
    <div class="field" style="margin: 0; min-width: 260px; flex: 1;">
      <label for="delivery-search" class="caption" style="display: block; margin-bottom: 0.25rem;">Search Order #, Customer, Phone, or City</label>
      <input id="delivery-search" type="search" name="q" value="<?= e($q) ?>" placeholder="Search Order ID, Customer Name, Phone, City..." class="search-input">
    </div>
    <?php if ($q !== ''): ?>
      <div class="row" style="align-items: center;">
        <a href="/delivery/orders?tab=<?= e($activeTab) ?>" class="btn ghost btn-sm">Reset</a>
      </div>
    <?php endif; ?>
  </form>
</section>

<section class="card" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;"><?= e($tabs[$activeTab] ?? 'Delivery Orders') ?> (<?= count($orders) ?>)</h2>
      <p class="caption muted" style="margin: 0.25rem 0 0 0;">
        Click any order row to inspect delivery address, customer contact, items, and available actions.
      </p>
    </div>
  </div>

  <?php if (empty($orders)): ?>
    <div class="empty" style="padding: 3rem 1rem; text-align: center;">
      <p style="margin: 0; font-size: 1rem; color: var(--color-muted, #6b7280);">
        No delivery orders found in <strong><?= e($tabs[$activeTab] ?? 'this view') ?></strong>.
      </p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Order ID</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Customer</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Destination</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">Payment</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; text-align: right;">Total</th>
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
            } elseif (in_array($st, ['out_for_delivery', 'processing'], true)) {
                $badgeClass = 'badge-info';
            } elseif ($st === 'pending') {
                $badgeClass = 'badge-warning';
            } elseif ($st === 'delivery_failed') {
                $badgeClass = 'badge-danger';
            }
            $payLabel = !empty($o['is_refunded'])
                ? 'Refunded'
                : (!empty($o['payment_status']) ? ucfirst((string)$o['payment_status']) . ' (' . strtoupper((string)$o['payment_method']) . ')' : 'Unverified (No Record)');
            ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6); cursor: pointer;"
                data-modal-open="delivery-modal-<?= (int)$o['order_id'] ?>"
                class="order-table-row">
              <td style="padding: 0.875rem 1rem; font-weight: 600; font-family: var(--font-mono, monospace);">
                #ORD-<?= (int)$o['order_id'] ?>
                <div class="caption muted" style="font-weight: 400;"><?= date('M j, Y', strtotime((string)$o['order_date'])) ?></div>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <div style="font-weight: 500;"><?= e($o['customer_name']) ?></div>
                <div class="caption muted"><?= e($o['customer_phone'] ?: $o['customer_email'] ?: 'No contact') ?></div>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <div><?= e($o['delivery_address_line1'] ?? '') ?></div>
                <div class="caption muted"><?= e(implode(', ', array_filter([$o['delivery_city'] ?? '', $o['delivery_province'] ?? '', $o['delivery_postal_code'] ?? '']))) ?></div>
              </td>
              <td style="padding: 0.875rem 1rem;">
                <span class="badge <?= !empty($o['payment_eligible']) ? 'badge-success' : 'badge-warning' ?>" style="font-size: 0.75rem;">
                  <?= e($payLabel) ?>
                </span>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: right; font-weight: 700;">
                ₱<?= number_format((float)$o['total_amount'], 2) ?>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: center;">
                <span class="badge <?= $badgeClass ?>"><?= e(ucwords(str_replace('_', ' ', $st))) ?></span>
                <?php if (!empty($o['rescheduled_date'])): ?>
                  <div class="caption muted" style="font-size: 0.75rem; margin-top: 0.2rem; color: <?= !empty($o['rescheduled_future']) ? '#b91c1c' : '#0369a1' ?>;">
                    Scheduled: <?= date('M j, Y', strtotime((string)$o['rescheduled_date'])) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td style="padding: 0.875rem 1rem; text-align: right;" onclick="event.stopPropagation();">
                <button type="button" class="btn ghost btn-sm" data-modal-open="delivery-modal-<?= (int)$o['order_id'] ?>">
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

<?php
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
  $canDispatch = $isCourier
      && in_array($st, ['pending', 'confirmed', 'processing', 'delivery_failed'], true)
      && !empty($orderModal['payment_eligible'])
      && empty($orderModal['rescheduled_future']);
  $canCompleteOrFail = $isCourier
      && $st === 'out_for_delivery'
      && (int)($orderModal['delivery_employee_id'] ?? 0) === (int)($empId ?? 0);
  ?>
  <dialog class="modal modal-wide" id="delivery-modal-<?= $ordId ?>" data-open-on-load="<?= $isAutoOpen ?>" aria-labelledby="delivery-modal-title-<?= $ordId ?>">
    <div class="modal-head">
      <div>
        <h3 id="delivery-modal-title-<?= $ordId ?>" style="margin: 0; font-size: 1.25rem;">
          Delivery Order #ORD-<?= $ordId ?>
        </h3>
        <span class="caption muted">Placed on <?= date('M j, Y g:i A', strtotime((string)$orderModal['order_date'])) ?></span>
      </div>
      <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close dialog">&times;</button>
    </div>

    <div class="modal-body" style="padding: 1.5rem;">
      <div class="grid grid-3" style="gap: 1rem; margin-bottom: 1.5rem;">
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Customer</span>
          <div style="font-weight: 600; font-size: 1rem; margin-top: 0.25rem;"><?= e($orderModal['customer_name']) ?></div>
          <?php if (!empty($orderModal['customer_phone'])): ?>
            <div class="caption" style="margin-top: 0.25rem;">📞 <?= e($orderModal['customer_phone']) ?></div>
          <?php endif; ?>
          <?php if (!empty($orderModal['customer_email'])): ?>
            <div class="caption" style="margin-top: 0.25rem;">✉️ <?= e($orderModal['customer_email']) ?></div>
          <?php endif; ?>
        </div>

        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Payment Eligibility</span>
          <?php if (!empty($orderModal['is_refunded'])): ?>
            <div style="margin-top: 0.35rem;"><span class="badge badge-danger">Refunded (Ineligible)</span></div>
          <?php elseif (!empty($orderModal['payment_status'])): ?>
            <div class="caption muted" style="margin-top: 0.25rem;">Method: <strong><?= e($orderModal['payment_method']) ?></strong></div>
            <div class="caption" style="margin-top: 0.25rem;">
              Status:
              <span class="badge <?= !empty($orderModal['payment_eligible']) ? 'badge-success' : 'badge-warning' ?>" style="font-size: 0.75rem;">
                <?= e(ucfirst((string)$orderModal['payment_status'])) ?>
              </span>
            </div>
          <?php else: ?>
            <div style="margin-top: 0.35rem;"><span class="badge badge-warning">No Payment Record</span></div>
            <div class="caption muted" style="margin-top: 0.25rem;">Blocked until payment or COD is verified.</div>
          <?php endif; ?>
        </div>

        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Delivery Status</span>
          <div style="margin-top: 0.25rem;">
            <span class="badge <?= $st === 'completed' ? 'badge-success' : ($st === 'delivery_failed' ? 'badge-danger' : 'badge-info') ?>" style="font-size: 0.95rem; padding: 0.35rem 0.65rem;">
              <?= e(ucwords(str_replace('_', ' ', $st))) ?>
            </span>
          </div>
          <?php if (!empty($orderModal['delivery_employee_name'])): ?>
            <div class="caption muted" style="margin-top: 0.4rem;">
              Courier: <strong><?= e($orderModal['delivery_employee_name']) ?></strong>
            </div>
          <?php endif; ?>
          <?php if (!empty($orderModal['rescheduled_date'])): ?>
            <div class="caption muted" style="margin-top: 0.25rem;">
              Scheduled Date: <strong><?= date('M j, Y', strtotime((string)$orderModal['rescheduled_date'])) ?></strong>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
        <h4 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; color: #166534;">Delivery Address &amp; Notes</h4>
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
          <p class="caption muted" style="margin: 0.4rem 0 0 0;"><strong>Notes:</strong> <?= e($orderModal['notes']) ?></p>
        <?php endif; ?>
      </div>

      <?php if ($st === 'delivery_failed'): ?>
        <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem;">
          <h4 style="margin: 0 0 0.5rem 0; font-size: 0.95rem; color: #991b1b;">Previous Delivery Failure</h4>
          <p style="margin: 0; font-size: 0.9rem; color: #b91c1c;">
            <strong>Reason:</strong> <?= e($orderModal['delivery_failure_reason'] ?: 'Not specified') ?>
          </p>
          <?php if (!empty($orderModal['delivery_failed_at'])): ?>
            <p class="caption muted" style="margin: 0.25rem 0 0 0;">
              Failed at: <?= date('M j, Y g:i A', strtotime((string)$orderModal['delivery_failed_at'])) ?>
            </p>
          <?php endif; ?>
          <?php if (!empty($orderModal['rescheduled_future'])): ?>
            <p class="caption" style="margin: 0.5rem 0 0 0; color: #991b1b; font-weight: 600;">
              Re-delivery is scheduled for <?= date('l, M j, Y', strtotime((string)$orderModal['rescheduled_date'])) ?> and cannot be dispatched early.
            </p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <h4 style="margin: 0 0 0.75rem 0; font-size: 1rem;">Ordered Items</h4>
      <div style="border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; overflow: hidden; margin-bottom: 1.5rem;">
        <table style="width: 100%; border-collapse: collapse;">
          <thead>
            <tr style="background: var(--color-bg-subtle, #f9fafb); border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left;">
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem;">Product</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem;">SKU</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; text-align: center;">Qty</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; text-align: right;">Unit Price</th>
              <th style="padding: 0.6rem 0.75rem; font-size: 0.8rem; text-align: right;">Line Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (($orderModal['items'] ?? []) as $it): ?>
              <?php $lTot = (float)$it['unit_price'] * (int)$it['quantity']; ?>
              <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
                <td style="padding: 0.65rem 0.75rem; font-weight: 500;"><?= e($it['product_name']) ?></td>
                <td style="padding: 0.65rem 0.75rem; font-family: var(--font-mono, monospace); font-size: 0.85rem;" class="muted"><?= e($it['sku'] ?: '—') ?></td>
                <td style="padding: 0.65rem 0.75rem; text-align: center; font-weight: 600;"><?= (int)$it['quantity'] ?></td>
                <td style="padding: 0.65rem 0.75rem; text-align: right;">₱<?= number_format((float)$it['unit_price'], 2) ?></td>
                <td style="padding: 0.65rem 0.75rem; text-align: right; font-weight: 600;">₱<?= number_format($lTot, 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background: var(--color-bg-subtle, #f9fafb); border-top: 2px solid var(--color-border, #e5e7eb);">
              <th colspan="4" style="padding: 0.75rem 1rem; text-align: right;">Total:</th>
              <th style="padding: 0.75rem 1rem; text-align: right; color: var(--color-primary, #047857);">₱<?= number_format((float)$orderModal['total_amount'], 2) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>

      <?php if ($isCourier): ?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem 1.25rem;">
          <h4 style="margin: 0 0 0.75rem 0; font-size: 0.95rem; color: #1e293b;">Courier Actions</h4>

          <?php if (in_array($st, ['pending', 'confirmed', 'processing', 'delivery_failed'], true)): ?>
            <?php if ($canDispatch): ?>
              <form method="post" action="/delivery/orders/<?= $ordId ?>/dispatch" style="margin: 0;">
                <?= \App\Core\CSRF::field() ?>
                <button type="submit" class="btn primary btn-sm">
                  <?= $st === 'delivery_failed' ? '🚚 Re-Dispatch Delivery' : '🚚 Claim &amp; Dispatch Delivery' ?>
                </button>
              </form>
            <?php else: ?>
              <p class="caption" style="margin: 0; color: #b91c1c;">
                <?php if (!empty($orderModal['rescheduled_future'])): ?>
                  Cannot dispatch early: scheduled for <?= date('M j, Y', strtotime((string)$orderModal['rescheduled_date'])) ?>.
                <?php elseif (empty($orderModal['payment_eligible'])): ?>
                  <?= e((string)($orderModal['payment_ineligible_reason'] ?? 'Order is not eligible for dispatch.')) ?>
                <?php else: ?>
                  This order is not available for dispatch.
                <?php endif; ?>
              </p>
            <?php endif; ?>

          <?php elseif ($canCompleteOrFail): ?>
            <div style="display: flex; flex-direction: column; gap: 1rem;">
              <form method="post" action="/delivery/orders/<?= $ordId ?>/complete" style="margin: 0;">
                <?= \App\Core\CSRF::field() ?>
                <button type="submit" class="btn primary btn-sm">✓ Mark Delivered (Completed)</button>
              </form>

              <form method="post" action="/delivery/orders/<?= $ordId ?>/fail" style="margin: 0; padding-top: 0.75rem; border-top: 1px solid #e2e8f0;">
                <?= \App\Core\CSRF::field() ?>
                <label for="fail-reason-<?= $ordId ?>" class="caption" style="display: block; font-weight: 600; color: #991b1b; margin-bottom: 0.35rem;">
                  Report Delivery Failure (Reason required):
                </label>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                  <input type="text"
                         id="fail-reason-<?= $ordId ?>"
                         name="delivery_failure_reason"
                         maxlength="255"
                         required
                         placeholder="e.g., Customer unavailable, wrong address, unreachable"
                         style="flex: 1; min-width: 240px; padding: 0.45rem 0.65rem; border: 1px solid #fca5a5; border-radius: 6px;">
                  <button type="submit" class="btn danger btn-sm">⚠️ Mark Delivery Failed</button>
                </div>
              </form>
            </div>

          <?php elseif ($st === 'completed'): ?>
            <span class="badge badge-success">✓ Delivery completed.</span>
          <?php else: ?>
            <span class="caption muted">No courier actions available for this order.</span>
          <?php endif; ?>
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
