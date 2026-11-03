<div class="page-header">
  <div>
    <h1>Search Catalog &amp; Services</h1>
    <p class="muted">Find products, clinical veterinary treatments, and professional grooming services.</p>
  </div>
</div>

<form class="hero-search-form" method="get" action="/search" role="search" style="margin: 0 0 2rem;">
  <span class="hero-search-icon" aria-hidden="true">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
  </span>
  <input class="hero-search-input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search products, veterinary services, or grooming treatments..." aria-label="Search products, veterinary services, or grooming treatments" autofocus>
  <button class="hero-search-btn" type="submit">Search</button>
</form>

<?php if ($q === ''): ?>
  <div class="grid-3">
    <div class="card overview-card">
      <h3>Veterinary Clinic</h3>
      <p class="muted">Preventive exams, vaccinations, diagnostics, and dental care.</p>
      <a class="action-link" href="/services#veterinary">Explore Veterinary Services <span class="arrow" aria-hidden="true">&rarr;</span></a>
    </div>
    <div class="card overview-card">
      <h3>Professional Grooming</h3>
      <p class="muted">Oatmeal baths, breed-standard styling, nail care, and de-shedding.</p>
      <a class="action-link" href="/services#grooming">View Grooming Options <span class="arrow" aria-hidden="true">&rarr;</span></a>
    </div>
    <div class="card overview-card">
      <h3>Pet Store</h3>
      <p class="muted">Everyday nutrition, grooming supplies, and enrichment toys.</p>
      <a class="action-link" href="/products">Browse Pet Store <span class="arrow" aria-hidden="true">&rarr;</span></a>
    </div>
  </div>
<?php else: ?>
  <?php $totalMatches = count($products) + count($groomingServices) + count($vetServices); ?>
  <p class="muted" style="margin-bottom: 1.5rem;">Found <strong><?= $totalMatches ?></strong> result<?= $totalMatches === 1 ? '' : 's' ?> for “<?= e($q) ?>”.</p>

  <?php if ($vetServices || $groomingServices): ?>
    <h2>Matching Services</h2>
    <div class="grid" style="margin-bottom: 2rem;">
      <?php foreach (array_merge($vetServices, $groomingServices) as $s): ?>
        <div class="card overview-card">
          <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
            <span class="caption"><?= $s['service_category'] === 'grooming' ? 'Grooming Services' : 'Veterinary Clinic' ?></span>
            <span class="caption"><?= (int)$s['estimated_duration_minutes'] ?> mins</span>
          </div>
          <h3><?= e($s['service_name']) ?></h3>
          <p class="muted" style="margin: 0.35rem 0 0.85rem;"><?= e($s['description']) ?></p>
          <div class="row" style="justify-content: space-between; border-top: 1px solid var(--line); padding-top: 0.7rem;">
            <span class="price"><?= money($s['base_price']) ?></span>
            <a class="action-link" href="/services">View on Services page <span class="arrow" aria-hidden="true">&rarr;</span></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($products): ?>
    <h2>Matching Products</h2>
    <div class="grid">
      <?php foreach ($products as $p):
        $buId = (int)$p['business_unit_id'];
        $bpId = (int)$p['business_product_id'];
      ?>
        <div class="card overview-card">
          <div class="row" style="justify-content: space-between; margin-bottom: 0.35rem;">
            <span class="caption"><?= e($p['business_unit_name']) ?></span>
            <span class="badge <?= (int)$p['available'] > 0 ? ((int)$p['available'] <= 5 ? 'warn' : 'ok') : 'err' ?> tabular"><?= (int)$p['available'] > 0 ? (int)$p['available'] . ' in stock' : 'Out of stock' ?></span>
          </div>
          <h3><a href="/shop/<?= $buId ?>/product/<?= $bpId ?>"><?= e($p['product_name']) ?></a></h3>
          <p class="muted" style="margin: 0.35rem 0 0.85rem;"><?= e($p['description']) ?></p>
          <div class="row" style="justify-content: space-between; border-top: 1px solid var(--line); padding-top: 0.7rem;">
            <span class="price"><?= money($p['selling_price']) ?></span>
            <a class="action-link" href="/shop/<?= $buId ?>/product/<?= $bpId ?>">Product details <span class="arrow" aria-hidden="true">&rarr;</span></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($totalMatches === 0): ?>
    <div class="card">
      <h3>No exact matches for “<?= e($q) ?>”</h3>
      <p class="muted">Try searching for keywords like <em>shampoo</em>, <em>bath</em>, <em>vaccine</em>, <em>dental</em>, or browse our full catalogs directly.</p>
      <div class="row" style="margin-top: 1rem;">
        <a class="action-link" href="/services">Browse all services <span class="arrow" aria-hidden="true">&rarr;</span></a>
        <a class="action-link" href="/products" style="margin-left: 1rem;">Browse all products <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

