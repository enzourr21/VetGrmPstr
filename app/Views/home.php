<?php $signedIn = (bool)\App\Core\Session::get('customer_id'); ?>

<!-- Section 1: Hero (No green header tag badge; Brand Headline + Value Prop + Problem/Solution + Centered Search Bar) -->
<section class="hero-card hero-centered" data-scene="hero">
  <h1 class="hero-brand-title">VETGRPET</h1>
  <p class="hero-subtitle">Unified Pet Care &amp; Clinic Management</p>
  <p class="hero-description">
    Managing pet healthcare, grooming schedules, and essential supplies across disconnected platforms creates friction for pet owners and providers alike. VETGRPET unifies clinical veterinary records, professional grooming appointments, and an integrated pet store into a single, seamless ecosystem.
  </p>

  <form class="hero-search-form" method="get" action="/search" role="search">
    <span class="hero-search-icon" aria-hidden="true">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
      </svg>
    </span>
    <input
      class="hero-search-input"
      type="search"
      name="q"
      placeholder="Search products, veterinary services, or grooming treatments..."
      aria-label="Search products, veterinary services, or grooming treatments"
    >
    <button class="hero-search-btn" type="submit">Search</button>
  </form>
</section>

<!-- Section 2: High-Level Overview Pillars (No green badges, no exhaustive line-item tables) -->
<section data-scene="services" id="home-pillars">
  <div class="section-header">
    <div>
      <h2>Three Business Units, One Cohesive Platform</h2>
      <p class="muted">Explore our dedicated service menus and product catalogs tailored to each area of pet care.</p>
    </div>
    <a class="action-link" href="/services">View all services <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <div class="grid-3">
    <!-- Card 1: Veterinary Clinic -->
    <article class="card overview-card">
      <h3>Veterinary Clinic</h3>
      <p class="muted">
        Comprehensive preventive wellness exams, core vaccinations, laboratory diagnostics, and clinical dental care delivered by licensed veterinary professionals. Structured SOAP medical records keep every consultation organized and accurate.
      </p>
      <div class="overview-card-footer">
        <a class="action-link" href="/services#veterinary">Explore Veterinary Services <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </article>

    <!-- Card 2: Professional Grooming -->
    <article class="card overview-card">
      <h3>Professional Grooming</h3>
      <p class="muted">
        Gentle oatmeal baths, breed-appropriate haircuts, nail trimming, enzymatic teeth brushing, and deep de-shedding treatments tailored to your pet's coat. Request multi-pet appointments online with transparent receptionist confirmation.
      </p>
      <div class="overview-card-footer">
        <a class="action-link" href="/services#grooming">View Grooming Options <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </article>

    <!-- Card 3: Pet Store -->
    <article class="card overview-card">
      <h3>Pet Store</h3>
      <p class="muted">
        Curated everyday nutrition, gentle grooming supplies, clinical animal-health essentials, and interactive enrichment toys with live stock availability. Separate persistent carts ensure orders stay organized by business unit.
      </p>
      <div class="overview-card-footer">
        <a class="action-link" href="/products">Browse Pet Store <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </article>
  </div>
</section>

<?php if ($signedIn): ?>
<section style="margin-top: 2.75rem;">
  <div class="section-header" style="margin-top: 0;">
    <div>
      <h2>Your Customer Account</h2>
      <p class="muted">Quickly manage your registered pets, grooming requests, and orders.</p>
    </div>
  </div>
  <div class="grid-3">
    <a class="card overview-card hoverable" href="/appointments/new">
      <h3>Book Grooming</h3>
      <p class="muted">Select your registered pets and submit a new grooming appointment request.</p>
      <span class="action-link">Book grooming <span class="arrow" aria-hidden="true">&rarr;</span></span>
    </a>
    <a class="card overview-card hoverable" href="/pets">
      <h3>My Registered Pets</h3>
      <p class="muted">Add or update your pets' species, breed, weight, and care notes.</p>
      <span class="action-link">Manage pets <span class="arrow" aria-hidden="true">&rarr;</span></span>
    </a>
    <a class="card overview-card hoverable" href="/orders">
      <h3>Order History</h3>
      <p class="muted">Track your submitted pickup and delivery orders across business units.</p>
      <span class="action-link">View orders <span class="arrow" aria-hidden="true">&rarr;</span></span>
    </a>
  </div>
</section>
<?php endif; ?>

<!-- Section 3: Staffs & Customer Reviews Overview (No green badges) -->
<section data-scene="community" id="home-community">
  <div class="section-header">
    <div>
      <h2>Dedicated Leadership &amp; Care Specialists</h2>
      <p class="muted">Meet the business unit managers, veterinarians, nurses, and stylists behind VETGRPET.</p>
    </div>
    <a class="action-link" href="/staffs">Meet our staffs <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <div class="grid-3">
    <?php foreach (array_slice($staffs, 0, 3) as $st):
      $initials = mb_strtoupper(mb_substr($st['first_name'], 0, 1) . mb_substr($st['last_name'], 0, 1));
    ?>
      <div class="card overview-card">
        <div class="row" style="justify-content: space-between; margin-bottom: 0.65rem;">
          <span class="staff-avatar" aria-hidden="true"><?= e($initials) ?></span>
          <?php if (!empty($st['units'])): ?><span class="caption"><?= e($st['units']) ?></span><?php endif; ?>
        </div>
        <h3><?= e($st['first_name'] . ' ' . $st['last_name']) ?></h3>
        <p class="muted"><?= e($st['position']) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="section-header">
    <div>
      <h2>Customer Reviews</h2>
      <p class="muted">Authentic experiences shared by registered pet owners—never pre-filled with fabricated testimonials.</p>
    </div>
    <a class="action-link" href="/reviews"><?= $signedIn ? 'Write a review' : 'Explore customer reviews' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
  </div>

  <?php if (!$reviews): ?>
    <div class="card overview-card">
      <div class="row" style="justify-content: space-between;">
        <div>
          <h3>Share Your Experience</h3>
          <p class="muted">No customer reviews have been submitted yet. Visit our Customer Reviews page to leave the first verified review.</p>
        </div>
        <a class="action-link" href="/reviews">Submit a review <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </div>
    </div>
  <?php else: ?>
    <div class="grid-3">
      <?php foreach (array_slice($reviews, 0, 3) as $rev): ?>
        <article class="card overview-card">
          <div class="row" style="justify-content: space-between; margin-bottom: 0.4rem;">
            <span class="stars" aria-label="<?= (int)$rev['rating'] ?> out of 5 stars"><?= str_repeat('★', (int)$rev['rating']) . str_repeat('☆', 5 - (int)$rev['rating']) ?></span>
            <?php if (!empty($rev['business_unit_name'])): ?><span class="caption"><?= e($rev['business_unit_name']) ?></span><?php endif; ?>
          </div>
          <h3><?= e($rev['review_title']) ?></h3>
          <p class="muted" style="margin: 0.4rem 0 0.75rem;"><?= e($rev['comment']) ?></p>
          <p class="caption">By <strong><?= e($rev['first_name'] . ' ' . $rev['last_initial'] . '.') ?></strong></p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
