<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-head">
  <div>
    <p class="eyebrow">Operational Snapshot</p>
    <h1>Pet Store Overview</h1>
    <p class="muted">Operational summary covering confirmed appointments, pending refund requests, and recently successful sales.</p>
  </div>
</div>

<section class="grid grid-4" style="margin-bottom: 2rem;">
  <article class="card stat-card">
    <span class="eyebrow">Confirmed Appointments</span>
    <strong class="stat-value"><?= count($confirmedAppointments) ?></strong>
    <p class="caption">Scheduled appointments awaiting customer arrival</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Pending Refunds</span>
    <strong class="stat-value" style="<?= count($pendingRefunds) > 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
      <?= count($pendingRefunds) ?>
    </strong>
    <p class="caption">Refund requests requiring inspection &amp; review</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Recent Walk-in Sales</span>
    <strong class="stat-value"><?= count($recentWalkInSales) ?></strong>
    <p class="caption">Counter sales completed in-store</p>
  </article>
  <article class="card stat-card">
    <span class="eyebrow">Recent Online Orders</span>
    <strong class="stat-value"><?= count($recentOnlineOrders) ?></strong>
    <p class="caption">Customer online purchases completed</p>
  </article>
</section>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem; margin-bottom: 2rem;">
  <!-- 1. Confirmed Appointments -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <h2 style="margin: 0;">Confirmed Appointments (<?= count($confirmedAppointments) ?>)</h2>
      <span class="caption">Filtered to Confirmed status</span>
    </div>
    <?php if (empty($confirmedAppointments)): ?>
      <div class="empty">No confirmed appointments scheduled for Pet Store at this time.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Appt #</th>
              <th>Customer</th>
              <th>Date &amp; Time</th>
              <th>Type</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($confirmedAppointments as $a): ?>
              <tr>
                <td><strong>#<?= (int)$a['appointment_id'] ?></strong></td>
                <td>
                  <strong><?= e($a['customer_name']) ?></strong>
                  <?php if (!empty($a['contact_number'])): ?>
                    <div class="caption"><?= e($a['contact_number']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?= e(date('M j, Y', strtotime((string)$a['preferred_date']))) ?>
                  <div class="caption"><?= e(date('g:i A', strtotime((string)$a['preferred_time']))) ?></div>
                </td>
                <td><?= e($a['appointment_type'] ?? 'General') ?></td>
                <td><span class="badge badge-success"><?= e($a['status_name']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- 2. Pending Refund Requests -->
  <section class="card">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <h2 style="margin: 0;">Pending Refund Requests (<?= count($pendingRefunds) ?>)</h2>
      <span class="caption">Awaiting manager approval</span>
    </div>
    <?php if (empty($pendingRefunds)): ?>
      <div class="empty">No pending refund requests. All transactions are clear.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Ref #</th>
              <th>Channel</th>
              <th>Customer</th>
              <th>Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingRefunds as $rf): ?>
              <tr>
                <td>
                  <strong><?= e($rf['channel'] === 'Online Order' ? 'ORD #' . $rf['ref_id'] : 'SALE #' . $rf['ref_id']) ?></strong>
                  <div class="caption"><?= e(date('M j, Y g:i A', strtotime((string)$rf['created_at']))) ?></div>
                </td>
                <td><span class="badge secondary"><?= e($rf['channel']) ?></span></td>
                <td><?= e($rf['customer_name']) ?></td>
                <td><strong style="color: var(--danger, #dc2626);"><?= peso((float)$rf['amount']) ?></strong></td>
                <td><span class="badge badge-warning">Pending Review</span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<!-- 3. Recently Successful Sales (Distinguishing Walk-in Sales vs Online Orders) -->
<section class="card" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1.25rem;">
    <div>
      <h2 style="margin: 0 0 0.25rem 0;">Recently Successful Sales</h2>
      <p class="caption" style="margin: 0;">Operational summary distinguishing in-store walk-in sales from online orders.</p>
    </div>
    <a href="/manager/sales<?= $unitQuery ?>" class="btn secondary btn-sm">Open Sales Workspace &rarr;</a>
  </div>

  <div class="grid grid-2" style="gap: 1.5rem; align-items: start;">
    <!-- Walk-in Sales -->
    <div style="background: var(--bg-subtle, #f9fafb); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border, #e5e7eb);">
      <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
        <h3 style="margin: 0; font-size: 1.05rem;">
          <span class="badge" style="background: #e0f2fe; color: #0369a1; margin-right: 0.4rem;">Walk-in</span>
          Walk-in Sales
        </h3>
        <span class="caption"><?= count($recentWalkInSales) ?> recent</span>
      </div>
      <?php if (empty($recentWalkInSales)): ?>
        <div class="empty" style="padding: 1.5rem 1rem;">No recent walk-in sales recorded.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Receipt / Sale</th>
                <th>Customer</th>
                <th>Items</th>
                <th>Total</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentWalkInSales as $ws): ?>
                <tr>
                  <td>
                    <a href="/manager/sales<?= $unitQuery ?>&tab=walkin&view=<?= (int)$ws['sale_id'] ?>">
                      <strong>#REC-S-<?= (int)$ws['sale_id'] ?></strong>
                    </a>
                  </td>
                  <td><?= e($ws['customer_name']) ?></td>
                  <td><?= (int)$ws['item_count'] ?> item(s)</td>
                  <td><strong><?= peso((float)$ws['total_amount']) ?></strong></td>
                  <td class="caption"><?= e(date('M j, g:i A', strtotime((string)$ws['sale_date']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Online Orders -->
    <div style="background: var(--bg-subtle, #f9fafb); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border, #e5e7eb);">
      <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
        <h3 style="margin: 0; font-size: 1.05rem;">
          <span class="badge" style="background: #fef3c7; color: #92400e; margin-right: 0.4rem;">Online</span>
          Online Orders
        </h3>
        <span class="caption"><?= count($recentOnlineOrders) ?> recent</span>
      </div>
      <?php if (empty($recentOnlineOrders)): ?>
        <div class="empty" style="padding: 1.5rem 1rem;">No recent online orders recorded.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Order #</th>
                <th>Customer</th>
                <th>Fulfillment</th>
                <th>Total</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentOnlineOrders as $oo): ?>
                <tr>
                  <td>
                    <a href="/manager/sales<?= $unitQuery ?>&tab=online&view=<?= (int)$oo['order_id'] ?>">
                      <strong>#ORD-<?= (int)$oo['order_id'] ?></strong>
                    </a>
                  </td>
                  <td><?= e($oo['customer_name']) ?></td>
                  <td><span class="badge secondary"><?= e(ucfirst((string)$oo['fulfillment_method'])) ?></span></td>
                  <td><strong><?= peso((float)$oo['total_amount']) ?></strong></td>
                  <td class="caption"><?= e(date('M j, g:i A', strtotime((string)$oo['order_date']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
