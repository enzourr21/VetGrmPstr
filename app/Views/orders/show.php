<a class="breadcrumb-link" href="/orders"><span class="arrow-back" aria-hidden="true">&larr;</span> Back to orders</a>
<div class="page-header">
  <div>
    <h1>Order #<?= (int)$o['order_id'] ?></h1>
    <p class="muted tabular"><?= e($o['business_unit_name']) ?> · <?= e($o['order_date']) ?></p>
  </div>
  <span class="badge warn"><?= e(str_replace('_', ' ', ucfirst($o['order_status']))) ?></span>
</div>

<div class="card" style="margin-bottom: 1.5rem;">
  <p>
    <strong><?= e($o['business_unit_name']) ?></strong> · <span class="tabular"><?= e($o['order_date']) ?></span><br>
    Status: <span class="badge warn"><?= e(str_replace('_', ' ', ucfirst($o['order_status']))) ?></span><br>
    Fulfillment: <strong><?= e(ucfirst($o['fulfillment_method'])) ?></strong>
  </p>
  <?php if ($o['fulfillment_method'] === 'delivery'): ?>
    <p class="muted">Deliver to: <?= e(implode(', ', array_filter([$o['delivery_address_line1'], $o['delivery_address_line2'], $o['delivery_city'], $o['delivery_province'], $o['delivery_postal_code']]))) ?></p>
  <?php endif; ?>
  <?php if ($o['notes']): ?><p class="muted">Notes: <?= e($o['notes']) ?></p><?php endif; ?>
</div>

<table>
  <thead><tr><th>Product</th><th>Unit price</th><th>Qty</th><th class="right">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach ($lines as $l): ?>
    <tr><td><strong><?= e($l['product_name']) ?></strong></td><td class="tabular"><?= money($l['unit_price']) ?></td><td class="tabular"><?= (int)$l['quantity'] ?></td><td class="right price"><?= money((float)$l['unit_price'] * (int)$l['quantity']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="3" class="right">Total</th><th class="right price"><?= money($o['total_amount']) ?></th></tr></tfoot>
</table>

<p class="muted" style="margin-top: 1rem;">Stock reservation and payment are not implemented yet; this order is recorded as pending.</p>
