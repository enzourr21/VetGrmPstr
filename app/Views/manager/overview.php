<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-head">
  <div>
    <p class="eyebrow">Operational Snapshot</p>
    <h1>Pet Store Overview</h1>
    <p class="muted">Operational summary covering stocks running low, pending refund requests, and recent sales.</p>
  </div>
</div>

<section class="grid grid-3" style="margin-bottom: 2rem;">
  <article class="card stat-card" id="petstore-stocks-running-low-stat">
    <span class="eyebrow">Stocks Running Low</span>
    <strong class="stat-value" style="<?= count($lowStockProducts) > 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
      <?= count($lowStockProducts) ?>
    </strong>
    <p class="caption">Products at or below low-stock threshold (≤ 10)</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Pending Refunds</span>
    <strong class="stat-value" style="<?= count($pendingRefunds) > 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
      <?= count($pendingRefunds) ?>
    </strong>
    <p class="caption">Refund requests requiring inspection &amp; review</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Recent Sales</span>
    <strong class="stat-value"><?= count($recentSales) ?></strong>
    <p class="caption">Unified walk-in and online sales transactions</p>
  </article>
</section>

<?php if (!empty($confirmedAppointments)): ?>
  <section class="card" style="margin-bottom: 2rem;">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0;">Confirmed Appointments (<?= count($confirmedAppointments) ?>)</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Upcoming confirmed customer appointments for Pet Store.</p>
      </div>
      <span class="badge badge-success"><?= count($confirmedAppointments) ?> Confirmed</span>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Appointment #</th>
            <th>Date &amp; Time</th>
            <th>Customer</th>
            <th>Contact</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($confirmedAppointments as $ca): ?>
            <tr>
              <td><strong>#<?= (int)$ca['appointment_id'] ?></strong></td>
              <td><?= e(date('M j, Y', strtotime((string)$ca['preferred_date']))) ?> · <?= e((string)$ca['preferred_time']) ?></td>
              <td><strong><?= e($ca['customer_name']) ?></strong></td>
              <td class="caption"><?= e($ca['customer_phone'] ?: '—') ?></td>
              <td><span class="badge badge-success"><?= e($ca['status_name']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<div class="grid grid-2 petstore-overview-middle-grid" style="align-items: stretch; gap: 1.5rem; margin-bottom: 2rem;">
  <!-- 1. Stocks Running Low -->
  <section class="card petstore-overview-equal-card" id="petstore-stocks-running-low" style="overflow-x: hidden; display: flex; flex-direction: column; height: 100%;">
    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap;">
      <div>
        <h2 style="margin: 0;">Stocks Running Low (<?= count($lowStockProducts) ?>)</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Pet Store products with available stock at or below threshold</p>
      </div>
      <span class="badge <?= count($lowStockProducts) > 0 ? 'badge-danger' : 'badge-success' ?>" style="font-weight: 600;">
        <?= count($lowStockProducts) ?> Low Stock
      </span>
    </div>

    <?php if (empty($lowStockProducts)): ?>
      <div class="empty" style="flex: 1 1 auto; display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem;">No products currently low on stock. All items are sufficiently stocked.</div>
    <?php else: ?>
      <?php $displayedLowStock = array_slice($lowStockProducts, 0, 2); ?>
      <div class="table-wrap" style="flex: 1 1 auto; min-height: 0; max-height: 420px; overflow-y: auto;">
        <table>
          <thead>
            <tr>
              <th>Product Name</th>
              <th style="text-align: right; width: 140px;">Available Qty</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($displayedLowStock as $prod): ?>
              <tr>
                <td><strong><?= e($prod['product_name']) ?></strong></td>
                <td class="tabular" style="text-align: right;">
                  <strong style="<?= (int)$prod['available'] === 0 ? 'color: var(--danger, #dc2626);' : 'color: var(--warn, #b45309);' ?>">
                    <?= (int)$prod['available'] ?>
                  </strong>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
    <div style="margin-top: auto; padding-top: 1rem;">
      <a href="/manager/inventory<?= $unitQuery ?>" class="btn secondary btn-sm" style="width: 100%; text-align: center; justify-content: center;">
        Open Inventory &rarr;
      </a>
    </div>
  </section>

  <!-- 2. Pending Refund Requests -->
  <section class="card petstore-overview-equal-card" style="overflow-x: hidden; display: flex; flex-direction: column; height: 100%;">
    <div style="margin-bottom: 1rem;">
      <h2 style="margin: 0;">Pending Refund Requests (<?= count($pendingRefunds) ?>)</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">Customer requests awaiting manager review</p>
    </div>

    <?php if (empty($pendingRefunds)): ?>
      <div class="empty" style="flex: 1 1 auto; display: flex; align-items: center; justify-content: center; padding: 1.5rem 1rem;">No pending refund requests. All transactions are clear.</div>
    <?php else: ?>
      <div class="petstore-refund-list" style="flex: 1 1 auto; min-height: 0; max-height: 420px; overflow-y: auto; overflow-x: hidden; padding-right: 0.25rem;">
        <?php foreach ($pendingRefunds as $rf): ?>
          <div style="padding: 0.75rem 0.25rem; border-bottom: 1px solid var(--border, #e5e7eb); display: flex; flex-direction: column; gap: 0.4rem;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem; flex-wrap: wrap;">
              <div>
                <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=refunds&view=<?= (int)$rf['refund_request_id'] ?>">
                  <strong><?= e($rf['refund_reference']) ?></strong>
                </a>
                <span class="badge secondary" style="font-size: 0.75rem; margin-left: 0.35rem;"><?= e($rf['channel_label']) ?></span>
                <span class="caption" style="margin-left: 0.25rem;"><?= e($rf['transaction_ref']) ?></span>
              </div>
              <strong style="color: var(--danger, #dc2626); font-size: 1rem;"><?= peso((float)$rf['requested_amount']) ?></strong>
            </div>
            <div class="caption">
              <span><?= e($rf['customer_name']) ?></span>
              &bull;
              <span><?= e(date('M j, Y g:i A', strtotime((string)$rf['created_at']))) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top: auto; padding-top: 1rem;">
        <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=refunds" class="btn secondary btn-sm" style="width: 100%; text-align: center; justify-content: center;">
          Open Refund Requests Workspace &rarr;
        </a>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- 3. Recent Sales (Unified List with Channel Indicator) -->
<section class="card" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1.25rem;">
    <div>
      <h2 style="margin: 0 0 0.25rem 0;">Recent Sales</h2>
      <p class="caption" style="margin: 0;">Unified operational record of recent walk-in sales and online orders.</p>
    </div>
    <a href="/manager/sales<?= $unitQuery ?>" class="btn secondary btn-sm">Open Sales Workspace &rarr;</a>
  </div>

  <?php if (empty($recentSales)): ?>
    <div class="empty">No recent sales or online orders recorded for Pet Store.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width: 130px;">Sales Channel</th>
            <th>Ref / Receipt #</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total Amount</th>
            <th>Date &amp; Time</th>
            <th style="text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentSales as $sale): ?>
            <tr>
              <td>
                <?php if ($sale['channel'] === 'Walk-in'): ?>
                  <span class="badge" style="background: #e0f2fe; color: #0369a1; border: 1px solid rgba(3, 105, 161, 0.2); font-weight: 600;">
                    Walk-in
                  </span>
                <?php else: ?>
                  <span class="badge" style="background: #fef3c7; color: #92400e; border: 1px solid rgba(146, 64, 14, 0.2); font-weight: 600;">
                    Online Order
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($sale['tab']) ?>&view=<?= (int)$sale['ref_id'] ?>">
                  <strong><?= e($sale['ref_code']) ?></strong>
                </a>
              </td>
              <td><?= e($sale['customer_name']) ?></td>
              <td><?= (int)$sale['item_count'] ?> item(s)</td>
              <td><strong><?= peso((float)$sale['total_amount']) ?></strong></td>
              <td class="caption"><?= e(date('M j, Y g:i A', strtotime((string)$sale['date']))) ?></td>
              <td style="text-align: right;">
                <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=<?= e($sale['tab']) ?>&view=<?= (int)$sale['ref_id'] ?>" class="btn ghost btn-sm">
                  View Details
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
