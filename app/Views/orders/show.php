<?php
$payMethod = !empty($o['payment_method']) ? strtoupper((string)$o['payment_method']) : 'Pending';
$payStatus = !empty($o['payment_status']) ? ucfirst((string)$o['payment_status']) : 'Pending';
$deliveryFee = (float)($o['delivery_fee'] ?? 0.0);
$subtotalAmount = isset($o['subtotal_amount']) && $o['subtotal_amount'] !== null
    ? (float)$o['subtotal_amount']
    : max(0.0, (float)$o['total_amount'] - $deliveryFee);
?>
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
    Fulfillment: <strong><?= e(ucfirst($o['fulfillment_method'])) ?></strong><br>
    Payment: <strong><?= e($payMethod) ?></strong> (<span class="badge <?= ($o['payment_status'] ?? '') === 'completed' ? 'ok' : 'secondary' ?>"><?= e($payStatus) ?></span>)
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
  <tfoot>
    <tr><th colspan="3" class="right">Product subtotal</th><th class="right tabular"><?= money($subtotalAmount) ?></th></tr>
    <tr><th colspan="3" class="right">Delivery fee<?= $o['fulfillment_method'] === 'pickup' ? ' (Pickup)' : '' ?></th><th class="right tabular"><?= money($deliveryFee) ?></th></tr>
    <tr><th colspan="3" class="right">Total</th><th class="right price"><?= money($o['total_amount']) ?></th></tr>
  </tfoot>
</table>

<?php if (($o['payment_method'] ?? '') === 'cod' && ($o['payment_status'] ?? '') === 'pending'): ?>
  <p class="muted" style="margin-top: 1rem;">Stock is reserved for this Cash on Delivery order. Payment will be collected upon handover.</p>
<?php elseif (($o['payment_status'] ?? '') === 'completed'): ?>
  <p class="muted" style="margin-top: 1rem;">Payment has been verified and stock is reserved for fulfillment.</p>
<?php endif; ?>
