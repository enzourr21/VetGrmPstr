<?php
$signedIn = (bool)\App\Core\Session::get('customer_id');
$productsByUnit = $productsByUnit ?? [];
if (!$productsByUnit && !empty($allProducts)) {
    foreach ($allProducts as $prod) {
        $productsByUnit[(int)$prod['business_unit_id']][] = $prod;
    }
}
$unitImageMap = [
    \App\Models\Units::PET_STORE => '/assets/images/units/pet-store.jpg',
    \App\Models\Units::VET_CLINIC => '/assets/images/units/veterinary-clinic.jpg',
    \App\Models\Units::GROOMING => '/assets/images/units/grooming-services.jpg',
];
$unitImageById = [
    1 => '/assets/images/units/pet-store.jpg',
    2 => '/assets/images/units/veterinary-clinic.jpg',
    3 => '/assets/images/units/grooming-services.jpg',
];
?>
<div data-products-root data-signed-in="<?= $signedIn ? '1' : '0' ?>">
<div class="page-header">
  <div>
    <h1>Products &amp; Business Catalogs</h1>
  </div>
</div>

<h2 style="margin-top: 0;">Browse by Business Unit</h2>
<div class="grid" style="margin-bottom: 2.25rem;">
<?php foreach ($units as $u):
  $buId = (int)$u['business_unit_id'];
  $unitName = (string)$u['business_unit_name'];
  $n = (int)($counts[$buId] ?? 0);
  $unitImg = $unitImageMap[$unitName] ?? ($unitImageById[$buId] ?? '/assets/images/units/pet-store.jpg');
?>
  <article class="card overview-card unit-browse-card" data-unit-card="<?= $buId ?>" data-unit-modal-open="unit-modal-<?= $buId ?>">
    <div>
      <div class="unit-card-media">
        <img src="<?= e($unitImg) ?>" alt="<?= e($unitName) ?>" loading="lazy">
      </div>
      <div class="unit-card-body">
        <h3 style="margin-bottom: 0;"><?= e($unitName) ?></h3>
      </div>
    </div>
    <div class="overview-card-footer unit-card-footer">
      <button type="button" class="action-link unit-card-browse-btn" data-unit-modal-open="unit-modal-<?= $buId ?>" aria-haspopup="dialog" aria-controls="unit-modal-<?= $buId ?>">
        Browse products <span class="arrow" aria-hidden="true">&rarr;</span>
      </button>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php foreach ($units as $u):
  $buId = (int)$u['business_unit_id'];
  $unitName = (string)$u['business_unit_name'];
  $n = (int)($counts[$buId] ?? 0);
  $unitProducts = $productsByUnit[$buId] ?? [];
?>
<dialog class="modal modal-wide unit-products-modal" id="unit-modal-<?= $buId ?>" data-unit-modal="<?= $buId ?>" aria-labelledby="unit-modal-title-<?= $buId ?>">
  <div class="modal-head">
    <h3 id="unit-modal-title-<?= $buId ?>"><?= e($unitName) ?></h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close <?= e($unitName) ?> products">Close</button>
  </div>
  <div class="modal-body">
    <div class="flash flash-info unit-modal-feedback" data-unit-modal-feedback="<?= $buId ?>" role="status" hidden></div>
    <?php if (!$unitProducts): ?>
      <div class="empty-subtle">No products are currently available for online purchase in <?= e($unitName) ?>.</div>
    <?php else: ?>
      <div class="grid unit-modal-grid">
      <?php foreach ($unitProducts as $p):
        $in = (int)$p['available'] > 0;
        $bpId = (int)$p['business_product_id'];
      ?>
        <article class="card overview-card unit-modal-product-card" data-modal-product="<?= $bpId ?>">
          <div>
            <?php if (!empty($p['image_url'])): ?>
              <div class="unit-modal-product-thumb">
                <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['product_name']) ?>" loading="lazy">
              </div>
            <?php endif; ?>
            <div class="row" style="justify-content: space-between; margin-bottom: 0.45rem;">
              <span class="caption"><?= !empty($p['product_category']) ? e($p['product_category']) : e($unitName) ?></span>
              <?php if (!$in): ?>
                <span class="status err">Out of stock</span>
              <?php elseif ((int)$p['available'] <= 5): ?>
                <span class="status warn tabular">Only <?= (int)$p['available'] ?> left</span>
              <?php else: ?>
                <span class="status ok tabular">In stock (<?= (int)$p['available'] ?>)</span>
              <?php endif; ?>
            </div>
            <h3><a href="/shop/<?= $buId ?>/product/<?= $bpId ?>"><?= e($p['product_name']) ?></a></h3>
            <?php if (!empty($p['description'])): ?>
              <p class="muted" style="margin-bottom: 0.25rem;"><?= e($p['description']) ?></p>
            <?php endif; ?>
          </div>
          <div class="overview-card-footer">
            <span class="price"><?= money($p['selling_price']) ?></span>
            <form method="post" action="/cart/<?= $buId ?>/add" class="row" data-unit-add-cart-form data-unit-id="<?= $buId ?>" data-unit-name="<?= e($unitName) ?>" data-product-name="<?= e($p['product_name']) ?>">
              <?= \App\Core\CSRF::field() ?>
              <input type="hidden" name="business_product_id" value="<?= $bpId ?>">
              <input type="hidden" name="quantity" value="1">
              <button class="btn unit-add-cart-btn" type="submit" <?= $in ? '' : 'disabled' ?>>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <circle cx="9" cy="21" r="1"></circle>
                  <circle cx="20" cy="21" r="1"></circle>
                  <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
                </svg>
                <span>Add to cart</span>
              </button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="modal-foot">
    <span class="caption tabular" data-unit-modal-cart-summary="<?= $buId ?>"><?= $n ?> item<?= $n === 1 ? '' : 's' ?> in <?= e($unitName) ?> cart</span>
    <div class="row">
      <button type="button" class="btn ghost" data-modal-close>Close</button>
      <button type="button" class="btn tabular" data-unit-checkout="<?= $buId ?>" data-unit-name="<?= e($unitName) ?>" data-unit-count="<?= $n ?>" <?= $n > 0 ? '' : 'disabled' ?>>
        Checkout (<?= $n ?>)
      </button>
    </div>
  </div>
</dialog>
<?php endforeach; ?>

<dialog class="modal" id="guest-checkout-signin-modal" aria-labelledby="guest-checkout-signin-title">
  <div class="modal-head">
    <h3 id="guest-checkout-signin-title" data-signin-modal-title>Sign in required</h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close aria-label="Close">Close</button>
  </div>
  <div class="modal-body">
    <p class="muted" style="margin-bottom: 1.25rem;" data-signin-modal-message>
      Please sign in first to add items to your cart or proceed to checkout.
    </p>
    <div class="row" style="justify-content: flex-end; gap: 0.65rem;">
      <button type="button" class="btn secondary" data-modal-close data-continue-browsing>Continue Browsing</button>
      <a class="btn" href="/login" data-signin-proceed-link>Sign In</a>
    </div>
  </div>
</dialog>

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
          <span class="status err">Out of stock</span>
        <?php elseif ((int)$p['available'] <= 5): ?>
          <span class="status warn tabular">Only <?= (int)$p['available'] ?> left</span>
        <?php else: ?>
          <span class="status ok tabular">In stock (<?= (int)$p['available'] ?>)</span>
        <?php endif; ?>
      </div>
      <h3><a href="/shop/<?= $buId ?>/product/<?= $bpId ?>"><?= e($p['product_name']) ?></a></h3>
      <?php if (!empty($p['product_category'])): ?>
        <p class="caption" style="margin-bottom: 0.45rem;"><?= e($p['product_category']) ?></p>
      <?php endif; ?>
      <?php if (!empty($p['description'])): ?>
        <p class="muted" style="margin-bottom: 0.25rem;"><?= e($p['description']) ?></p>
      <?php endif; ?>
    </div>
    <div class="overview-card-footer">
      <span class="price"><?= money($p['selling_price']) ?></span>
      <?php if ($signedIn): ?>
        <form method="post" action="/cart/<?= $buId ?>/add" class="row" data-unit-add-cart-form data-unit-id="<?= $buId ?>" data-product-name="<?= e($p['product_name']) ?>">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="business_product_id" value="<?= $bpId ?>">
          <input class="qty" type="number" name="quantity" value="1" min="1" max="99" aria-label="Quantity for <?= e($p['product_name']) ?>">
          <button class="btn unit-add-cart-btn" type="submit" <?= $in ? '' : 'disabled' ?>>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <circle cx="9" cy="21" r="1"></circle>
              <circle cx="20" cy="21" r="1"></circle>
              <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path>
            </svg>
            <span>Add to cart</span>
          </button>
        </form>
      <?php else: ?>
        <button type="button" class="action-link unit-card-browse-btn" data-unit-modal-open="unit-modal-<?= $buId ?>" data-focus-product="<?= $bpId ?>" aria-haspopup="dialog" aria-controls="unit-modal-<?= $buId ?>">
          View details <span class="arrow" aria-hidden="true">&rarr;</span>
        </button>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
