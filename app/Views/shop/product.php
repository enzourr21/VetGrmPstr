<?php $in = (int)$p['available'] > 0; ?>
<a class="breadcrumb-link" href="/shop/<?= (int)$unit['business_unit_id'] ?>"><span class="arrow-back" aria-hidden="true">&larr;</span> <?= e($unit['business_unit_name']) ?> products</a>
<article class="card">
  <div class="row" style="justify-content: space-between; margin-bottom: 0.5rem;">
    <span class="caption"><?= e($unit['business_unit_name']) ?> · <?= e($p['product_category']) ?></span>
    <?php if ($in): ?>
      <span class="badge ok tabular"><?= (int)$p['available'] ?> available</span>
    <?php else: ?>
      <span class="badge err">Out of stock</span>
    <?php endif; ?>
  </div>
  <h1><?= e($p['product_name']) ?></h1>
  <p class="muted" style="max-width: 65ch; margin: 0.65rem 0 1.25rem;"><?= e($p['description']) ?></p>
  <div class="overview-card-footer" style="margin-top: 1.25rem;">
    <span class="price" style="font-size: 1.35rem;"><?= money($p['selling_price']) ?></span>
    <form method="post" action="/cart/<?= (int)$unit['business_unit_id'] ?>/add" class="row">
      <?= \App\Core\CSRF::field() ?>
      <input type="hidden" name="business_product_id" value="<?= (int)$p['business_product_id'] ?>">
      <label for="quantity" style="margin:0">Quantity</label>
      <input id="quantity" class="qty" type="number" name="quantity" value="1" min="1" max="99">
      <button class="btn" type="submit" <?= $in ? '' : 'disabled' ?>>Add to cart</button>
    </form>
  </div>
</article>
