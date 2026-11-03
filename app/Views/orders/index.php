<div class="page-header">
  <div>
    <h1>My orders</h1>
    <p class="muted">View your submitted pickup and delivery orders across all business units.</p>
  </div>
  <a class="btn secondary" href="/products">Browse products</a>
</div>

<?php if (!$orders): ?>
  <div class="card">
    <h3>No orders yet</h3>
    <p class="muted" style="margin-bottom: 1rem;">Browse our business unit catalogs to place a pickup or delivery order.</p>
    <a class="btn" href="/products">Explore catalogs</a>
  </div>
<?php else: ?>
<table>
  <thead><tr><th>#</th><th>Shop</th><th>Date</th><th>Fulfillment</th><th>Status</th><th class="right">Total</th></tr></thead>
  <tbody>
  <?php foreach ($orders as $o): ?>
    <tr>
      <td class="tabular"><a href="/orders/<?= (int)$o['order_id'] ?>">#<?= (int)$o['order_id'] ?></a></td>
      <td><strong><?= e($o['business_unit_name']) ?></strong></td>
      <td class="tabular caption"><?= e($o['order_date']) ?></td>
      <td><?= e(ucfirst($o['fulfillment_method'])) ?></td>
      <td><span class="badge warn"><?= e(str_replace('_', ' ', ucfirst($o['order_status']))) ?></span></td>
      <td class="right price"><?= money($o['total_amount']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
