<?php
$codEnabled = !empty($paymentSettings['cod_enabled']);
$onlineEnabled = !empty($paymentSettings['online_enabled']);
$selectedFulfillment = $old['fulfillment_method'] ?? 'pickup';
$defaultPayment = $codEnabled ? 'cod' : ($onlineEnabled ? 'online' : '');
$selectedPayment = $old['payment_method'] ?? $defaultPayment;
if ($selectedPayment === 'cod' && !$codEnabled) {
    $selectedPayment = $onlineEnabled ? 'online' : '';
}
if ($selectedPayment === 'online' && !$onlineEnabled) {
    $selectedPayment = $codEnabled ? 'cod' : '';
}
$minThreshold = (int)($minStockThreshold ?? 3);
$holdMins = (int)($holdMinutes ?? 3);
$subtotalAmount = isset($amounts['subtotal_amount']) ? (float)$amounts['subtotal_amount'] : (float)($subtotal ?? $total ?? 0);
$deliveryFeeConfigured = !empty($paymentSettings['delivery_fee_configured']);
$configuredDeliveryFee = ($deliveryFeeConfigured && $paymentSettings['delivery_fee'] !== null) ? (float)$paymentSettings['delivery_fee'] : null;
$deliveryTotalAmount = ($deliveryFeeConfigured && $configuredDeliveryFee !== null) ? round($subtotalAmount + $configuredDeliveryFee, 2) : null;
$deliveryFee = ($selectedFulfillment === 'pickup')
    ? 0.0
    : (($deliveryFeeConfigured && $configuredDeliveryFee !== null) ? $configuredDeliveryFee : null);
$finalTotal = ($deliveryFee !== null) ? round($subtotalAmount + $deliveryFee, 2) : null;
$noPaymentAvailable = !$codEnabled && !$onlineEnabled;
$submitBlocked = $noPaymentAvailable || ($selectedFulfillment === 'delivery' && !$deliveryFeeConfigured);
?>
<a class="breadcrumb-link" href="/cart/<?= (int)$unit['business_unit_id'] ?>"><span class="arrow-back" aria-hidden="true">&larr;</span> Back to <?= e($unit['business_unit_name']) ?> cart</a>
<div class="page-header">
  <div>
    <h1>Checkout · <?= e($unit['business_unit_name']) ?></h1>
    <p class="muted">Review your items, choose branch pickup or delivery, and select a payment method.</p>
  </div>
</div>

<?php foreach ($errors as $er): ?><div class="flash flash-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<?php if (!empty($activeHold)): ?>
  <div class="flash flash-info" role="status">
    You have an active online checkout hold (#<?= (int)$activeHold['attempt_id'] ?>) expiring at
    <strong class="tabular"><?= e($activeHold['expires_at']) ?> UTC</strong>.
    <a href="/checkout/attempts/<?= (int)$activeHold['attempt_id'] ?>">View pending payment hold &rarr;</a>
  </div>
<?php endif; ?>

<table>
  <thead><tr><th>Product</th><th>Price</th><th>Qty</th><th>Available</th><th class="right">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i):
    $avail = (int)$i['available'];
    $qty = (int)$i['quantity'];
    $eligible = $avail >= $minThreshold && $avail >= $qty;
  ?>
    <tr>
      <td>
        <strong><?= e($i['product_name']) ?></strong>
        <?php if ($avail < $minThreshold): ?>
          <br><span class="status warn">Requires at least <?= $minThreshold ?> in stock (<?= $avail ?> available)</span>
        <?php elseif ($avail < $qty): ?>
          <br><span class="status err">Only <?= $avail ?> available</span>
        <?php endif; ?>
      </td>
      <td class="tabular"><?= money($i['selling_price']) ?></td>
      <td class="tabular"><?= $qty ?></td>
      <td class="tabular"><span class="status <?= $eligible ? 'ok' : 'warn' ?>"><?= $avail ?></span></td>
      <td class="right price"><?= money((float)$i['selling_price'] * $qty) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot data-checkout-summary>
    <tr>
      <th colspan="4" class="right">Product subtotal</th>
      <th class="right tabular" data-checkout-subtotal><?= money($subtotalAmount) ?></th>
    </tr>
    <tr>
      <th colspan="4" class="right">
        Delivery fee
        <span class="caption muted" data-checkout-delivery-note>
          <?php if ($selectedFulfillment === 'pickup'): ?>
            (Pickup — no delivery fee)
          <?php elseif ($deliveryFeeConfigured): ?>
            (Configured delivery fee)
          <?php else: ?>
            (Delivery unavailable — fee not configured)
          <?php endif; ?>
        </span>
      </th>
      <th class="right tabular" data-checkout-delivery-fee><?= $deliveryFee !== null ? money($deliveryFee) : 'Unavailable' ?></th>
    </tr>
    <tr>
      <th colspan="4" class="right">Total (recalculated on the server when you submit)</th>
      <th class="right price" data-checkout-total><?= $finalTotal !== null ? money($finalTotal) : 'Unavailable' ?></th>
    </tr>
  </tfoot>
</table>

<p class="notice">
  Online purchases require at least <strong><?= $minThreshold ?> units</strong> in available stock before checkout is accepted.
  <?php if ($onlineEnabled): ?>
    <strong>Online Payment</strong> places a <strong><?= $holdMins ?>-minute temporary stock hold</strong> while awaiting server-side payment verification;
  <?php else: ?>
    <strong>Online Payment</strong> is currently unavailable because no payment provider is configured;
  <?php endif; ?>
  <strong>Cash on Delivery (COD)</strong> immediately confirms your order and reserves stock without a temporary hold.
  <?php if (!$deliveryFeeConfigured): ?>
    <strong>Delivery</strong> is currently unavailable because no valid delivery fee is configured for this shop (branch pickup has no delivery fee).
  <?php endif; ?>
</p>

<form method="post" action="/checkout/<?= (int)$unit['business_unit_id'] ?>" class="card" data-checkout-form
      data-subtotal-formatted="<?= e(money($subtotalAmount)) ?>"
      data-pickup-fee-formatted="<?= e(money(0.0)) ?>"
      data-pickup-total-formatted="<?= e(money($subtotalAmount)) ?>"
      data-delivery-fee-formatted="<?= ($deliveryFeeConfigured && $configuredDeliveryFee !== null) ? e(money($configuredDeliveryFee)) : '' ?>"
      data-delivery-total-formatted="<?= ($deliveryTotalAmount !== null) ? e(money($deliveryTotalAmount)) : '' ?>"
      data-delivery-fee-configured="<?= $deliveryFeeConfigured ? '1' : '0' ?>"
      data-no-payment-available="<?= $noPaymentAvailable ? '1' : '0' ?>">
  <?= \App\Core\CSRF::field() ?>
  <input type="hidden" name="checkout_token" value="<?= e($checkoutToken ?? '') ?>">

  <fieldset style="border:0;padding:0;margin:0 0 1.25rem 0;">
    <legend style="font-weight: 700; margin-bottom: 0.45rem;">Fulfillment Method</legend>
    <div class="form-grid-2">
      <label class="check" style="margin: 0;">
        <input type="radio" name="fulfillment_method" value="pickup" <?= $selectedFulfillment === 'pickup' ? 'checked' : '' ?>>
        <span>
          <strong>Pickup at the branch</strong><br>
          <span class="caption">Collect your order directly at <?= e($unit['business_unit_name']) ?> (no delivery fee)</span>
        </span>
      </label>
      <label class="check" style="margin: 0;<?= !$deliveryFeeConfigured ? ' opacity: 0.6; cursor: not-allowed;' : '' ?>">
        <input type="radio" name="fulfillment_method" value="delivery" <?= $selectedFulfillment === 'delivery' ? 'checked' : '' ?> <?= !$deliveryFeeConfigured ? 'disabled' : '' ?>>
        <span>
          <strong>Delivery</strong>
          <?php if (!$deliveryFeeConfigured): ?>
            <span class="meta-label">Unavailable</span>
          <?php endif; ?><br>
          <span class="caption">
            <?php if ($deliveryFeeConfigured && $configuredDeliveryFee !== null): ?>
              Ship to your specified street address (<?= money($configuredDeliveryFee) ?> delivery fee)
            <?php else: ?>
              Currently unavailable (no valid delivery fee configured for this shop)
            <?php endif; ?>
          </span>
        </span>
      </label>
    </div>
  </fieldset>

  <fieldset style="border:0;padding:0;margin:0 0 1.25rem 0;">
    <legend style="font-weight: 700; margin-bottom: 0.45rem;">Payment Method</legend>
    <div class="form-grid-2">
      <?php if ($codEnabled): ?>
        <label class="check" style="margin: 0;">
          <input type="radio" name="payment_method" value="cod" <?= $selectedPayment === 'cod' ? 'checked' : '' ?>>
          <span>
            <strong>Cash on Delivery (COD)</strong><br>
            <span class="caption">Confirm order immediately and reserve stock; pay cash upon handover</span>
          </span>
        </label>
      <?php endif; ?>
      <label class="check" style="margin: 0;<?= !$onlineEnabled ? ' opacity: 0.6; cursor: not-allowed;' : '' ?>">
        <input type="radio" name="payment_method" value="online" <?= $selectedPayment === 'online' ? 'checked' : '' ?> <?= !$onlineEnabled ? 'disabled' : '' ?>>
        <span>
          <strong>Online Payment</strong>
          <?php if (!$onlineEnabled): ?>
            <span class="meta-label">Unavailable</span>
          <?php endif; ?><br>
          <span class="caption">
            <?php if ($onlineEnabled): ?>
              Hold stock for <?= $holdMins ?> minutes and proceed to online payment verification
            <?php else: ?>
              Currently unavailable (no online payment provider is configured yet)
            <?php endif; ?>
          </span>
        </span>
      </label>
    </div>
    <?php if (!$codEnabled): ?>
      <p class="caption muted" style="margin-top: 0.45rem;">Cash on Delivery is currently disabled for <?= e($unit['business_unit_name']) ?>.</p>
    <?php endif; ?>
    <?php if ($noPaymentAvailable): ?>
      <p class="flash flash-error" role="alert" style="margin-top: 0.65rem;">No payment method is currently available for this shop. Please contact the store.</p>
    <?php endif; ?>
  </fieldset>

  <div class="delivery-fields-wrap" data-delivery-fields>
    <div class="row" style="justify-content: space-between; margin-bottom: 0.65rem;">
      <strong style="font-size: 0.95rem;">Delivery Address</strong>
      <span class="caption" data-delivery-hint>Delivery address (only needed for delivery)</span>
    </div>

    <div class="form-grid-2">
      <div class="field">
        <div class="field-label-row"><label for="address_line1" style="margin:0">Address line 1</label></div>
        <input id="address_line1" name="address_line1" type="text" value="<?= e($addr['address_line1'] ?? '') ?>">
      </div>
      <div class="field">
        <div class="field-label-row"><label for="address_line2" style="margin:0">Address line 2</label><span class="label-optional">Optional</span></div>
        <input id="address_line2" name="address_line2" type="text" value="<?= e($addr['address_line2'] ?? '') ?>">
      </div>
    </div>

    <div class="form-grid-3" style="margin-top: 0.85rem;">
      <div class="field">
        <div class="field-label-row"><label for="city" style="margin:0">City</label></div>
        <input id="city" name="city" type="text" value="<?= e($addr['city'] ?? '') ?>">
      </div>
      <div class="field">
        <div class="field-label-row"><label for="province" style="margin:0">Province / State</label></div>
        <input id="province" name="province" type="text" value="<?= e($addr['province'] ?? '') ?>">
      </div>
      <div class="field">
        <div class="field-label-row"><label for="postal_code" style="margin:0">Postal code</label></div>
        <input id="postal_code" name="postal_code" type="text" value="<?= e($addr['postal_code'] ?? '') ?>">
      </div>
    </div>
  </div>

  <div class="field" style="margin-top: 1rem;">
    <div class="field-label-row">
      <label for="notes" style="margin:0">Notes</label>
      <span class="label-optional">Optional</span>
    </div>
    <textarea id="notes" name="notes" rows="2"><?= e($old['notes'] ?? '') ?></textarea>
  </div>

  <div class="row" style="margin-top: 1.25rem; justify-content: space-between;">
    <a class="btn secondary" href="/cart/<?= (int)$unit['business_unit_id'] ?>">Back to cart</a>
    <button class="btn" type="submit" data-checkout-submit <?= $submitBlocked ? 'disabled' : '' ?>>Place order</button>
  </div>
</form>
