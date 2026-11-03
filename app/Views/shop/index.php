<?php $signedIn = (bool)\App\Core\Session::get('customer_id'); ?>
<div class="page-header">
  <div>
    <h1>Products &amp; Business Catalogs</h1>
    <p class="muted">Each business unit maintains its own independent cart and pricing. Adding items to a cart does not reserve stock.</p>
  </div>
</div>

<h2 style="margin-top: 0;">Browse by Business Unit</h2>
<div class="grid" style="margin-bottom: 2.25rem;">
<?php foreach ($units as $u): $n = (int)($counts[$u['business_unit_id']] ?? 0); ?>
  <article class="card overview-card">
    <div>
      <div class="row" style="justify-content: space-between; margin-bottom: 0.45rem;">
        <h3><?= e($u['business_unit_name']) ?></h3>
        <?php if ($signedIn): ?>
          <span class="badge tabular"><?= $n ?> item<?= $n === 1 ? '' : 's' ?> in cart</span>
        <?php endif; ?>
      </div>
      <p class="muted">View unit-specific products, live stock availability, and dedicated cart.</p>
    </div>
    <div class="overview-card-footer">
      <a class="btn" href="/shop/<?= (int)$u['business_unit_id'] ?>">Browse products</a>
      <?php if ($signedIn): ?>
        <a class="btn secondary" href="/cart/<?= (int)$u['business_unit_id'] ?>">View cart</a>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php if (!empty($allProducts)): ?>
<h2>All Available Products</h2>
<div class="grid">
<?php foreach ($allProducts as $p):
  $in = (int)$p['available'] > 0;
  $buId = (int)$p['business_unit_id'];
  $bpId = (int)$p['business_product_id'];
?>
  <article class="card overview-card">
    <div>
      <div class="row" style="justify-content: space-between; margin-bottom: 0.45rem;">
        <span class="caption"><?= e($p['business_unit_name']) ?></span>
        <?php if (!$in): ?>
          <span class="badge err">Out of stock</span>
        <?php elseif ((int)$p['available'] <= 5): ?>
          <span class="badge warn">Only <?= (int)$p['available'] ?> left</span>
        <?php else: ?>
          <span class="badge ok">In stock (<?= (int)$p['available'] ?>)</span>
        <?php endif; ?>
      </div>
      <h3><a href="/shop/<?= $buId ?>/product/<?= $bpId ?>"><?= e($p['product_name']) ?></a></h3>
      <p class="caption" style="margin-bottom: 0.45rem;"><?= e($p['product_category']) ?></p>
      <p class="muted" style="margin-bottom: 0.25rem;"><?= e($p['description']) ?></p>
    </div>
    <div class="overview-card-footer">
      <span class="price"><?= money($p['selling_price']) ?></span>
      <?php if ($signedIn): ?>
        <form method="post" action="/cart/<?= $buId ?>/add" class="row">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="business_product_id" value="<?= $bpId ?>">
          <input class="qty" type="number" name="quantity" value="1" min="1" max="99" aria-label="Quantity for <?= e($p['product_name']) ?>">
          <button class="btn" type="submit" <?= $in ? '' : 'disabled' ?>>Add to cart</button>
        </form>
      <?php else: ?>
        <a class="action-link" href="/shop/<?= $buId ?>/product/<?= $bpId ?>">View details <span class="arrow" aria-hidden="true">&rarr;</span></a>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
