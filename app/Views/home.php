<?php
$signedIn = (bool)\App\Core\Session::get('customer_id');

$businessUnitCards = [
    [
        'name' => \App\Models\Units::PET_STORE,
        'href' => '/products',
        'cta' => 'Browse Pet Store',
        'image' => '/assets/images/units/pet-store.jpg',
        'crop' => 'center center',
        'alt' => 'Pet Store shelves stocked with pet supplies, food, and toys',
    ],
    [
        'name' => \App\Models\Units::VET_CLINIC,
        'href' => '/services#veterinary',
        'cta' => 'Explore Veterinary Services',
        'image' => '/assets/images/units/veterinary-clinic.jpg',
        'crop' => 'center 22%',
        'alt' => 'Veterinarian examining a dog at the Veterinary Clinic',
    ],
    [
        'name' => \App\Models\Units::GROOMING,
        'href' => '/services#grooming',
        'cta' => 'View Grooming Options',
        'image' => '/assets/images/units/grooming-services.jpg',
        'crop' => 'center 22%',
        'alt' => 'Pet groomer styling a dog in the Grooming Services studio',
    ],
];
?>

<!-- Section 1: Hero -->
<section class="hero-card home-hero-split" data-scene="hero" id="home-hero">
  <div class="home-hero-copy">
    <h1 class="home-hero-title">Care, grooming, and everyday supplies for every stage of your pet&rsquo;s life.</h1>
    <p class="home-hero-lead">
      PawCariño connects pet owners with our Veterinary Clinic, Grooming Services studio, and Pet Store in one place.
    </p>
    <div class="home-hero-actions">
      <a class="btn home-cta-get-started" href="/login">Get Started</a>
    </div>
  </div>

  <div class="home-hero-visual">
    <div class="home-hero-media" data-unit-media>
      <img
        src="/assets/images/units/hero-pet-care.jpg"
        alt="Happy healthy dogs outdoors cared for with PawCariño"
        width="1080"
        height="721"
        decoding="async"
        data-fallback-img
      >
      <div class="home-unit-fallback" hidden aria-hidden="true">
        <span class="home-unit-fallback-label">PawCariño Pet Care</span>
      </div>
    </div>
  </div>
</section>

<!-- Section 2: Explore Our Pet Care Businesses + Relocated Search Bar -->
<section class="home-section" data-scene="services" id="home-businesses" aria-labelledby="home-units-heading">
  <div class="section-header home-section-header">
    <div>
      <h2 id="home-units-heading">Explore Our Pet Care Businesses</h2>
    </div>
    <a class="action-link" href="/services">View all services <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <div class="grid-3 home-units-grid">
    <?php foreach ($businessUnitCards as $unit): ?>
      <article class="card overview-card home-unit-card">
        <div>
          <a class="home-unit-media-link" href="<?= e($unit['href']) ?>" tabindex="-1" aria-hidden="true">
            <div class="home-unit-media" data-unit-media>
              <img
                src="<?= e($unit['image']) ?>"
                alt="<?= e($unit['alt']) ?>"
                width="960"
                height="640"
                decoding="async"
                style="object-position: <?= e($unit['crop']) ?>;"
                data-fallback-img
              >
              <div class="home-unit-fallback" hidden aria-hidden="true">
                <span class="home-unit-fallback-label"><?= e($unit['name']) ?></span>
              </div>
            </div>
          </a>
          <div class="home-unit-body">
            <h3><?= e($unit['name']) ?></h3>
          </div>
        </div>
        <div class="overview-card-footer home-unit-footer">
          <a class="action-link" href="<?= e($unit['href']) ?>"><?= e($unit['cta']) ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="home-discovery-bar">
    <label for="home-catalog-search" class="home-search-label">Looking for a specific product, veterinary service, or grooming treatment?</label>
    <form class="hero-search-form home-secondary-search" method="get" action="/search" role="search">
      <span class="hero-search-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
      </span>
      <input
        id="home-catalog-search"
        class="hero-search-input"
        type="search"
        name="q"
        placeholder="Search products, veterinary services, or grooming treatments..."
        aria-label="Search products, veterinary services, or grooming treatments"
      >
      <button class="hero-search-btn" type="submit">Search</button>
    </form>
  </div>
</section>

<!-- Section 3: We Care for You -->
<section class="home-section" id="home-care-team" aria-labelledby="home-care-heading">
  <div class="section-header home-section-header">
    <div>
      <h2 id="home-care-heading">We Care for You</h2>
    </div>
    <a class="action-link" href="/staffs">Explore all staffs <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <?php if (!$staffs): ?>
    <div class="card overview-card home-empty-card">
      <div class="home-empty-row">
        <span class="home-empty-text">No staff profiles available right now.</span>
        <a class="action-link" href="/staffs">View staff directory <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </div>
  <?php else: ?>
    <div class="home-care-roster">
      <?php foreach (array_slice($staffs, 0, 4) as $st):
        $initials = mb_strtoupper(mb_substr($st['first_name'], 0, 1) . mb_substr($st['last_name'], 0, 1));
      ?>
        <article class="card home-care-card">
          <span class="staff-avatar home-care-avatar" aria-hidden="true"><?= e($initials) ?></span>
          <div class="home-care-meta">
            <div class="home-care-title-row">
              <h3 class="home-staff-name"><?= e($st['first_name'] . ' ' . $st['last_name']) ?></h3>
              <?php if (!empty($st['units'])): ?>
                <span class="staff-unit-pill"><?= e($st['units']) ?></span>
              <?php endif; ?>
            </div>
            <p class="home-staff-position"><?= e($st['position']) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Section 4: Why PawCariño? -->
<section class="home-section home-why-section" id="home-why-pawcarino" aria-labelledby="home-why-heading">
  <div class="section-header home-section-header">
    <div>
      <h2 id="home-why-heading">Why PawCariño?</h2>
    </div>
  </div>

  <div class="home-why-bento">
    <article class="card home-why-feature-lead">
      <div class="home-why-lead-copy">
        <div class="home-why-head">
          <span class="home-why-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
          </span>
          <h3>Unit-Specific Shopping &amp; Live Stock</h3>
        </div>
        <p>Check real-time product availability and pricing for each business unit, with independent shopping carts and pickup or delivery checkout.</p>
        <a class="action-link" href="/products">Browse Business Catalogs <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
      <div class="home-why-ui-preview" aria-hidden="true">
        <div class="home-preview-card">
          <div class="home-preview-row">
            <span class="home-preview-title">Adult Dog Kibble 2kg</span>
            <span class="home-preview-status is-ok">In stock</span>
          </div>
          <div class="home-preview-sub">Pet Store · Independent Pet Store Cart</div>
        </div>
        <div class="home-preview-card">
          <div class="home-preview-row">
            <span class="home-preview-title">Full Grooming Package</span>
            <span class="home-preview-status">60 mins</span>
          </div>
          <div class="home-preview-sub">Grooming Services · Multi-pet online request</div>
        </div>
      </div>
    </article>

    <div class="home-why-grid">
      <article class="card home-why-tile">
        <div>
          <div class="home-why-head">
            <span class="home-why-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><line x1="8" y1="11" x2="16" y2="11"/><line x1="8" y1="15" x2="16" y2="15"/></svg>
            </span>
            <h3>Transparent Service Menus</h3>
          </div>
          <p>Compare Veterinary Clinic and Grooming Services descriptions, estimated durations, and base rates upfront.</p>
        </div>
        <a class="action-link" href="/services">View service rates <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </article>

      <article class="card home-why-tile">
        <div>
          <div class="home-why-head">
            <span class="home-why-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M9 16l2 2 4-4"/></svg>
            </span>
            <h3>Online Grooming Requests</h3>
          </div>
          <p>Select multiple pets and grooming treatments in a single request and choose your preferred appointment date and time.</p>
        </div>
        <a class="action-link" href="<?= $signedIn ? '/appointments/new' : '/login' ?>"><?= $signedIn ? 'Request appointment' : 'Sign in to book' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </article>

      <article class="card home-why-tile">
        <div>
          <div class="home-why-head">
            <span class="home-why-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            </span>
            <h3>Dedicated Business-Unit Carts</h3>
          </div>
          <p>Keep Pet Store, Veterinary Clinic, and Grooming Services purchases neatly separated in unit-specific carts.</p>
        </div>
      </article>

      <article class="card home-why-tile">
        <div>
          <div class="home-why-head">
            <span class="home-why-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </span>
            <h3>Pet Profiles &amp; Order History</h3>
          </div>
          <p>Save your pets&rsquo; species, breed, and care notes, track order and appointment statuses, and leave verified reviews.</p>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- Section 5: Customer Reviews -->
<section class="home-section" data-scene="community" id="home-reviews" aria-labelledby="home-reviews-heading">
  <div class="section-header home-section-header">
    <div>
      <h2 id="home-reviews-heading">Customer Reviews</h2>
    </div>
    <a class="action-link" href="/reviews"><?= $signedIn ? 'Write a review' : 'Explore customer reviews' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <?php if (!$reviews): ?>
    <div class="card overview-card home-empty-card">
      <div class="home-empty-row">
        <span class="home-empty-text">No customer reviews yet.</span>
        <a class="action-link" href="/reviews"><?= $signedIn ? 'Write a review' : 'Submit a review' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </div>
  <?php else: ?>
    <div class="grid-3 home-reviews-grid">
      <?php foreach (array_slice($reviews, 0, 3) as $rev): ?>
        <article class="card overview-card home-review-card">
          <div>
            <div class="home-review-head">
              <span class="stars" aria-label="<?= (int)$rev['rating'] ?> out of 5 stars"><?= str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5 - (int)$rev['rating']) ?></span>
              <?php if (!empty($rev['business_unit_name'])): ?>
                <span class="staff-unit-pill"><?= e($rev['business_unit_name']) ?></span>
              <?php endif; ?>
            </div>
            <h3 class="home-review-title"><?= e($rev['review_title']) ?></h3>
            <p class="home-review-comment"><?= e($rev['comment']) ?></p>
          </div>
          <p class="caption home-review-author">By <strong><?= e($rev['first_name'] . ' ' . $rev['last_initial'] . '.') ?></strong></p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
