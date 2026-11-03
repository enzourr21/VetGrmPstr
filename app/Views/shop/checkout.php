<a class="breadcrumb-link" href="/cart/<?= (int)$unit['business_unit_id'] ?>"><span class="arrow-back" aria-hidden="true">&larr;</span> Back to <?= e($unit['business_unit_name']) ?> cart</a>
<div class="page-header">
  <div>
    <h1>Checkout · <?= e($unit['business_unit_name']) ?></h1>
    <p class="muted">Review your items and choose branch pickup or delivery.</p>
  </div>
</div>

<?php foreach ($errors as $er): ?><div class="flash flash-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<table>
  <thead><tr><th>Product</th><th>Price</th><th>Qty</th><th class="right">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr><td><strong><?= e($i['product_name']) ?></strong></td><td class="tabular"><?= money($i['selling_price']) ?></td><td class="tabular"><?= (int)$i['quantity'] ?></td><td class="right price"><?= money((float)$i['selling_price'] * (int)$i['quantity']) ?></td></tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="3" class="right">Total (recalculated on the server when you submit)</th><th class="right price"><?= money($total) ?></th></tr></tfoot>
</table>

<p class="notice">Stock is checked when you submit but is <strong>not reserved</strong> in this version. Payment is not handled online yet.</p>

<form method="post" action="/checkout/<?= (int)$unit['business_unit_id'] ?>" class="card" data-checkout-form>
  <?= \App\Core\CSRF::field() ?>
  <fieldset style="border:0;padding:0;margin:0">
    <legend style="font-weight: 700; margin-bottom: 0.45rem;">Fulfillment Method</legend>
    <div class="form-grid-2">
      <label class="check" style="margin: 0;">
        <input type="radio" name="fulfillment_method" value="pickup" checked>
        <span>
          <strong>Pickup at the branch</strong><br>
          <span class="caption">Collect your order directly at <?= e($unit['business_unit_name']) ?></span>
        </span>
      </label>
      <label class="check" style="margin: 0;">
        <input type="radio" name="fulfillment_method" value="delivery">
        <span>
          <strong>Delivery</strong><br>
          <span class="caption">Ship to your specified street address</span>
        </span>
      </label>
    </div>
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
    <textarea id="notes" name="notes" rows="2"></textarea>
  </div>

  <div class="row" style="margin-top: 1.25rem; justify-content: space-between;">
    <a class="btn secondary" href="/cart/<?= (int)$unit['business_unit_id'] ?>">Back to cart</a>
    <button class="btn" type="submit">Submit order</button>
  </div>
</form>
