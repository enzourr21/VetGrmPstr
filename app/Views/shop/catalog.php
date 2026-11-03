<a class="breadcrumb-link" href="/shop"><span class="arrow-back" aria-hidden="true">&larr;</span> All shops</a>
<div class="page-header">
  <div>
    <h1><?= e($unit['business_unit_name']) ?> products</h1>
    <p class="muted">Unit-isolated catalog and dedicated shopping cart for <?= e($unit['business_unit_name']) ?>.</p>
  </div>
  <a class="btn secondary tabular" href="/cart/<?= (int)$unit['business_unit_id'] ?>">Cart (<?= $cartCount ?>)</a>
</div>

<?php if (!$products): ?>
  <div class="card"><p class="muted">No products are listed for this business yet.</p></div>
<?php else: ?>
<div class="grid">
<?php foreach ($products as $p): $in = (int)$p['available'] > 0; ?>
  <article class="card overview-card">
    <div>
      <div class="row" style="justify-content: space-between; margin-bottom: 0.45rem;">
        <span class="caption"><?= e($p['product_category']) ?></span>
        <?php if (!$in): ?>
          <span class="badge err">Out of stock</span>
        <?php elseif ((int)$p['available'] <= 5): ?>
          <span class="badge warn">Only <?= (int)$p['available'] ?> left</span>
        <?php else: ?>
          <span class="badge ok">In stock</span>
        <?php endif; ?>
      </div>
      <h3><a href="/shop/<?= (int)$unit['business_unit_id'] ?>/product/<?= (int)$p['business_product_id'] ?>"><?= e($p['product_name']) ?></a></h3>
      <?php if (!empty($p['description'])): ?>
        <p class="muted" style="margin-top: 0.35rem;"><?= e($p['description']) ?></p>
      <?php endif; ?>
    </div>
    <div class="overview-card-footer">
      <span class="price"><?= money($p['selling_price']) ?></span>
      <form method="post" action="/cart/<?= (int)$unit['business_unit_id'] ?>/add" class="row">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="business_product_id" value="<?= (int)$p['business_product_id'] ?>">
        <label class="sr" for="q<?= (int)$p['business_product_id'] ?>" style="margin:0">Qty</label>
        <input id="q<?= (int)$p['business_product_id'] ?>" class="qty" type="number" name="quantity" value="1" min="1" max="99">
        <button class="btn" type="submit" <?= $in ? '' : 'disabled' ?>>Add to cart</button>
      </form>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
