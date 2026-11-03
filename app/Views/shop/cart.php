<a class="breadcrumb-link" href="/shop/<?= (int)$unit['business_unit_id'] ?>"><span class="arrow-back" aria-hidden="true">&larr;</span> Continue shopping at <?= e($unit['business_unit_name']) ?></a>
<div class="page-header">
  <div>
    <h1>Cart · <?= e($unit['business_unit_name']) ?></h1>
    <p class="muted">This cart belongs to <?= e($unit['business_unit_name']) ?> only. Items are saved to your account, and stock is checked when you submit your order.</p>
  </div>
</div>

<?php if (!$items): ?>
  <div class="card">
    <h3>Your cart is empty</h3>
    <p class="muted" style="margin-bottom: 1rem;">Explore the <?= e($unit['business_unit_name']) ?> catalog to add items to this cart.</p>
    <a class="btn" href="/shop/<?= (int)$unit['business_unit_id'] ?>">Browse products</a>
  </div>
<?php else: ?>
<table>
  <thead><tr><th>Product</th><th>Price</th><th>Quantity</th><th class="right">Subtotal</th><th class="right">Action</th></tr></thead>
  <tbody>
  <?php foreach ($items as $i): ?>
    <tr>
      <td>
        <strong><?= e($i['product_name']) ?></strong>
        <?php if ($i['product_status'] !== 'active'): ?><br><span class="badge err">No longer available</span>
        <?php elseif ((int)$i['available'] < (int)$i['quantity']): ?><br><span class="badge warn">Only <?= (int)$i['available'] ?> available</span><?php endif; ?>
      </td>
      <td class="tabular"><?= money($i['selling_price']) ?></td>
      <td>
        <form method="post" action="/cart/<?= (int)$unit['business_unit_id'] ?>/update" class="row">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="cart_item_id" value="<?= (int)$i['cart_item_id'] ?>">
          <input class="qty" type="number" name="quantity" value="<?= (int)$i['quantity'] ?>" min="1" max="99" aria-label="Quantity for <?= e($i['product_name']) ?>">
          <button class="btn secondary" type="submit">Update</button>
        </form>
      </td>
      <td class="right price"><?= money((float)$i['selling_price'] * (int)$i['quantity']) ?></td>
      <td class="right">
        <form method="post" action="/cart/<?= (int)$unit['business_unit_id'] ?>/remove" class="inline">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="cart_item_id" value="<?= (int)$i['cart_item_id'] ?>">
          <button class="btn danger" type="submit">Remove</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th colspan="3" class="right">Total</th><th class="right price"><?= money($total) ?></th><th></th></tr></tfoot>
</table>
<div class="row" style="margin-top: 1.25rem; justify-content: space-between;">
  <a class="btn secondary" href="/shop/<?= (int)$unit['business_unit_id'] ?>">Continue shopping</a>
  <a class="btn" href="/checkout/<?= (int)$unit['business_unit_id'] ?>">Proceed to checkout</a>
</div>
<?php endif; ?>
