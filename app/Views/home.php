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
            <h3 class="home-staff-name"><?= e($st['first_name'] . ' ' . $st['last_name']) ?></h3>
            <p class="home-staff-position"><?= e($st['position']) ?><?php if (!empty($st['units'])): ?> &middot; <span class="staff-unit"><?= e($st['units']) ?></span><?php endif; ?></p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- Section 4: Why PawCariño? -->
<section class="home-section home-why-section" id="home-why-pawcarino" aria-labelledby="home-why-heading">
  <div class="home-why-panel">
    <div class="home-why-intro">
      <h2 id="home-why-heading" class="home-why-title">Why PawCariño?</h2>
      <p class="home-why-lead">
        One account connecting our Pet Store, Veterinary Clinic, and Grooming Services&mdash;so live stock, service rates, grooming requests, and pet profiles stay organized.
      </p>
    </div>

    <ul class="home-why-list" role="list">
      <li class="home-why-item">
        <div class="home-why-item-body">
          <h3 class="home-why-item-title">Unit-Specific Shopping &amp; Live Stock</h3>
          <p class="home-why-item-desc">Check real-time product availability and pricing for each business unit, with dedicated unit carts and pickup or delivery checkout.</p>
        </div>
        <a class="action-link" href="/products">Browse business catalogs <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </li>

      <li class="home-why-item">
        <div class="home-why-item-body">
          <h3 class="home-why-item-title">Transparent Service Menus</h3>
          <p class="home-why-item-desc">Compare Veterinary Clinic and Grooming Services descriptions, estimated durations, and base rates upfront.</p>
        </div>
        <a class="action-link" href="/services">View service rates <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </li>

      <li class="home-why-item">
        <div class="home-why-item-body">
          <h3 class="home-why-item-title">Online Grooming Requests</h3>
          <p class="home-why-item-desc">Select multiple pets and grooming treatments in a single request and choose your preferred appointment date and time.</p>
        </div>
        <a class="action-link" href="<?= $signedIn ? '/appointments/new' : '/login' ?>"><?= $signedIn ? 'Request appointment' : 'Sign in to book' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </li>

      <li class="home-why-item">
        <div class="home-why-item-body">
          <h3 class="home-why-item-title">Pet Profiles &amp; Order History</h3>
          <p class="home-why-item-desc">Save your pets&rsquo; species, breed, and care notes, track order and appointment statuses, and share customer reviews.</p>
        </div>
        <a class="action-link" href="<?= $signedIn ? '/pets' : '/register' ?>"><?= $signedIn ? 'Manage pet profiles' : 'Create an account' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
      </li>
    </ul>
  </div>
</section>

<!-- Section 5: How PawCariño Works -->
<section class="home-section home-how-section" id="home-how-it-works" aria-labelledby="home-how-heading" data-how-walkthrough>
  <div class="home-how-shell">
    <div class="home-how-walkthrough" data-how-scroll>
      <div class="home-how-scroll-runway" data-how-runway>
        <div class="home-how-sticky-frame">
      <!-- Left Column: 5-Step Customer Journey Rail -->
      <div class="home-how-rail">
        <div class="home-how-rail-head">
          <h2 id="home-how-heading" class="home-how-rail-title">How PawCariño Works</h2>
          <div class="home-how-progress-track" aria-hidden="true">
            <span class="home-how-progress-fill" data-how-progress></span>
          </div>
        </div>

        <ol class="home-how-steps" role="list">
          <li class="home-how-step-item" data-how-step-item="0">
            <button type="button" class="home-how-step is-active" data-how-step="0" aria-pressed="true" aria-current="step">
              <span class="home-how-step-marker" aria-hidden="true">
                <span class="home-how-step-num tabular">01</span>
                <svg class="home-how-step-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              </span>
              <span class="home-how-step-copy">
                <span class="home-how-step-title">Get Started with PawCariño</span>
                <span class="home-how-step-desc">Sign in with your email or username, or create a customer account with email verification.</span>
              </span>
            </button>
          </li>

          <li class="home-how-step-item" data-how-step-item="1">
            <button type="button" class="home-how-step" data-how-step="1" aria-pressed="false">
              <span class="home-how-step-marker" aria-hidden="true">
                <span class="home-how-step-num tabular">02</span>
                <svg class="home-how-step-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              </span>
              <span class="home-how-step-copy">
                <span class="home-how-step-title">Your Pets&rsquo; Information</span>
                <span class="home-how-step-desc">Save each pet&rsquo;s species, breed, weight, and care notes&mdash;kept separate from unit orders and visits.</span>
              </span>
            </button>
          </li>

          <li class="home-how-step-item" data-how-step-item="2">
            <button type="button" class="home-how-step" data-how-step="2" aria-pressed="false">
              <span class="home-how-step-marker" aria-hidden="true">
                <span class="home-how-step-num tabular">03</span>
                <svg class="home-how-step-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              </span>
              <span class="home-how-step-copy">
                <span class="home-how-step-title">Shop for Your Pets</span>
                <span class="home-how-step-desc">Browse live stock in unit-specific catalogs, add to a dedicated cart, and check out for pickup or delivery.</span>
              </span>
            </button>
          </li>

          <li class="home-how-step-item" data-how-step-item="3">
            <button type="button" class="home-how-step" data-how-step="3" aria-pressed="false">
              <span class="home-how-step-marker" aria-hidden="true">
                <span class="home-how-step-num tabular">04</span>
                <svg class="home-how-step-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              </span>
              <span class="home-how-step-copy">
                <span class="home-how-step-title">Veterinary Care</span>
                <span class="home-how-step-desc">Compare clinic services, estimated durations, and base rates, then submit a consultation request.</span>
              </span>
            </button>
          </li>

          <li class="home-how-step-item" data-how-step-item="4">
            <button type="button" class="home-how-step" data-how-step="4" aria-pressed="false">
              <span class="home-how-step-marker" aria-hidden="true">
                <span class="home-how-step-num tabular">05</span>
                <svg class="home-how-step-check" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
              </span>
              <span class="home-how-step-copy">
                <span class="home-how-step-title">Grooming Services</span>
                <span class="home-how-step-desc">Choose one or more grooming treatments for your pets, pick a preferred time, and submit your request.</span>
              </span>
            </button>
          </li>
        </ol>

        <div class="home-how-rail-foot">
          <span class="home-how-foot-note">Be part of the platform</span>
          <a class="home-how-cta" href="/login">Get Started</a>
        </div>
      </div>

      <!-- Right Column: Persistent Sticky Demonstration Panel -->
      <div class="home-how-right-col">
        <div class="home-how-sticky-panel">
          <div class="home-how-stage-head">
            <span class="home-how-counter tabular" data-how-counter>Step 1 of 5</span>
            <div class="home-how-headlines">
              <p class="home-how-headline is-active" data-how-headline="0">One account for every visit and order.</p>
              <p class="home-how-headline" data-how-headline="1" aria-hidden="true">Keep every pet&rsquo;s profile up to date.</p>
              <p class="home-how-headline" data-how-headline="2" aria-hidden="true">Everything your pet needs, just a few clicks away.</p>
              <p class="home-how-headline" data-how-headline="3" aria-hidden="true">Give your furry friend the care they deserve.</p>
              <p class="home-how-headline" data-how-headline="4" aria-hidden="true">A little pampering goes a long way.</p>
            </div>
          </div>

          <div class="home-how-stage" aria-hidden="true">

            <!-- Scene 0: Get Started with PawCariño (Smartphone Account Experience) -->
            <div class="home-how-scene is-active" data-how-scene="0">
              <div class="how-phone" data-how-frame>
                <div class="how-phone-top">
                  <span class="how-phone-notch"></span>
                  <div class="how-phone-header">
                    <span class="how-phone-unit">PawCariño Account</span>
                    <span class="how-phone-pet">Sign in</span>
                  </div>
                </div>

                <div class="how-phone-screen">
                  <div class="how-subview is-visible" data-how-view="auth-email">
                    <div class="how-auth-steps">
                      <span class="how-auth-step is-current">1. Email</span>
                      <span class="how-auth-step">2. Password</span>
                    </div>
                    <div class="how-phone-label">Sign in to PawCariño</div>
                    <div class="how-mock-field">
                      <span class="how-mock-field-label">Email or username</span>
                      <span class="how-mock-input" data-how-auth-email>alex@example.com</span>
                    </div>
                    <div class="how-phone-cta-wrap">
                      <span class="how-mock-btn how-mock-btn-full" data-how-target="auth-continue">Continue</span>
                    </div>
                    <div class="how-auth-footnote">New here? Create an account with email verification</div>
                  </div>

                  <div class="how-subview" data-how-view="auth-password">
                    <div class="how-auth-steps">
                      <span class="how-auth-step is-done">1. Email &#10003;</span>
                      <span class="how-auth-step is-current">2. Password</span>
                    </div>
                    <div class="how-phone-label">Step 2 &middot; Enter Password</div>
                    <div class="how-mock-field">
                      <span class="how-mock-field-label">Password</span>
                      <span class="how-mock-input tabular" data-how-auth-pass>&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span>
                    </div>
                    <div class="how-phone-cta-wrap">
                      <span class="how-mock-btn how-mock-btn-full" data-how-target="auth-signin">Sign in</span>
                    </div>
                  </div>

                <div class="how-subview how-done-view" data-how-view="done">
                  <div class="how-done-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <div class="how-done-title">Welcome, Alex</div>
                  <div class="how-done-meta">Customer account active</div>
                  <div class="how-account-links">
                    <span class="how-account-link">My pets</span>
                    <span class="how-account-link">Orders</span>
                    <span class="how-account-link">Appointments</span>
                    <span class="how-account-link">Profile</span>
                  </div>
                </div>
              </div>

              <div class="how-cursor" data-how-cursor>
                <span class="how-cursor-ring"></span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                  <path d="M5.5 3.5L19 12.2L12.7 13.7L9.8 19.8L5.5 3.5Z" fill="currentColor" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
              </div>
            </div>
          </div>

          <!-- Scene 1: Your Pets' Information (Smartphone Pet Profile Experience) -->
          <div class="home-how-scene" data-how-scene="1">
            <div class="how-phone" data-how-frame>
              <div class="how-phone-top">
                <span class="how-phone-notch"></span>
                <div class="how-phone-header">
                  <span class="how-phone-unit">My pets</span>
                  <span class="how-phone-pet">2 profiles</span>
                </div>
              </div>

              <div class="how-phone-screen">
                <div class="how-subview is-visible" data-how-view="pets-list">
                  <div class="how-phone-label">Registered pet profiles</div>
                  <div class="how-pet-list">
                    <div class="how-pet-card" data-how-pet="1">
                      <div class="how-pet-head">
                        <span class="how-pet-name">Bella</span>
                        <span class="how-pet-species">Dog</span>
                      </div>
                      <div class="how-pet-meta tabular">Golden Retriever &middot; Female &middot; 26.5 kg</div>
                      <div class="how-pet-note">Sensitive around clippers; oatmeal shampoo</div>
                      <div class="how-pet-foot">
                        <span class="how-pet-edit-link" data-how-target="pet-edit">Edit profile &rarr;</span>
                      </div>
                    </div>

                    <div class="how-pet-card">
                      <div class="how-pet-head">
                        <span class="how-pet-name">Milo</span>
                        <span class="how-pet-species">Cat</span>
                      </div>
                      <div class="how-pet-meta tabular">Domestic Shorthair &middot; Male &middot; 4.2 kg</div>
                    </div>
                  </div>
                </div>

                <div class="how-subview" data-how-view="pets-edit">
                  <div class="how-phone-label">Edit pet &middot; Bella</div>
                  <div class="how-pet-fields">
                    <div class="how-pet-field-row">
                      <span class="how-mock-field-label">Weight (kg)</span>
                      <span class="how-mock-input tabular" data-how-pet-weight>26.5</span>
                    </div>
                    <div class="how-pet-field-row">
                      <span class="how-mock-field-label">Care notes</span>
                      <span class="how-mock-input">Oatmeal bath; gentle ear drying</span>
                    </div>
                  </div>
                  <div class="how-phone-cta-wrap">
                    <span class="how-mock-btn how-mock-btn-full" data-how-target="pet-save">Save changes</span>
                  </div>
                </div>

                <div class="how-subview how-done-view" data-how-view="done">
                  <div class="how-done-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <div class="how-done-title">Pet Profile Updated</div>
                  <div class="how-done-meta">Bella &middot; Dog &middot; 27.0 kg &middot; Notes saved</div>
                  <div class="how-done-note">Ready for grooming requests</div>
                </div>
              </div>

              <div class="how-cursor" data-how-cursor>
                <span class="how-cursor-ring"></span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                  <path d="M5.5 3.5L19 12.2L12.7 13.7L9.8 19.8L5.5 3.5Z" fill="currentColor" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
              </div>
            </div>
          </div>

          <!-- Scene 2: Shop for Your Pets (Laptop / Browser Interface) -->
          <div class="home-how-scene" data-how-scene="2">
            <div class="how-browser" data-how-frame>
              <div class="how-browser-bar">
                <div class="how-window-dots">
                  <span></span><span></span><span></span>
                </div>
                <div class="how-browser-url">pawcarino / shop / pet-store</div>
                <div class="how-cart" data-how-target="store-cart">
                  <span>Cart</span>
                  <span class="how-cart-count tabular" data-how-cart-count>0</span>
                </div>
              </div>

              <div class="how-browser-body">
                <div class="how-subview is-visible" data-how-view="catalog">
                  <div class="how-view-head">
                    <span class="how-view-title">Pet Store products</span>
                    <span class="how-view-meta">Live stock</span>
                  </div>
                  <div class="how-product-grid">
                    <div class="how-product-card" data-how-product="1">
                      <div class="how-product-top">
                        <span class="how-cat-label">Food</span>
                        <span class="how-stock">In stock</span>
                      </div>
                      <div class="how-product-name">Adult Dog Kibble 2kg</div>
                      <div class="how-product-foot">
                        <span class="how-price tabular">$24.00</span>
                        <span class="how-mock-btn" data-how-target="store-add">
                          <span class="how-btn-idle">Add to cart</span>
                          <span class="how-btn-done">Added &#10003;</span>
                        </span>
                      </div>
                    </div>

                    <div class="how-product-card">
                      <div class="how-product-top">
                        <span class="how-cat-label">Grooming Supplies</span>
                        <span class="how-stock">In stock</span>
                      </div>
                      <div class="how-product-name">Oatmeal Pet Shampoo</div>
                      <div class="how-product-foot">
                        <span class="how-price tabular">$14.50</span>
                        <span class="how-mock-btn is-muted">Add to cart</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="how-subview" data-how-view="checkout">
                  <div class="how-view-head">
                    <span class="how-view-title">Checkout &middot; Pet Store</span>
                    <span class="how-view-meta">1 item</span>
                  </div>
                  <div class="how-checkout-summary">
                    <div class="how-checkout-line">
                      <span>Adult Dog Kibble 2kg &times; 1</span>
                      <strong class="tabular">$24.00</strong>
                    </div>
                    <div class="how-checkout-options">
                      <span class="how-option-choice is-picked">Branch Pickup &middot; $0.00</span>
                      <span class="how-option-choice is-picked">Cash on Delivery</span>
                    </div>
                  </div>
                  <div class="how-checkout-foot">
                    <div class="how-total-pair">
                      <span class="how-total-label">Total</span>
                      <strong class="how-price tabular">$24.00</strong>
                    </div>
                    <span class="how-mock-btn" data-how-target="store-place">Place Order</span>
                  </div>
                </div>

                <div class="how-subview how-done-view" data-how-view="done">
                  <div class="how-done-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <div class="how-done-title">Order #1042 Confirmed</div>
                  <div class="how-done-meta">Pet Store &middot; Branch Pickup &middot; Stock reserved</div>
                  <div class="how-done-note tabular">Total $24.00 &middot; COD</div>
                </div>
              </div>

              <div class="how-cursor" data-how-cursor>
                <span class="how-cursor-ring"></span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                  <path d="M5.5 3.5L19 12.2L12.7 13.7L9.8 19.8L5.5 3.5Z" fill="currentColor" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
              </div>
            </div>
          </div>

          <!-- Scene 3: Veterinary Care (Smartphone Appointment Interface) -->
          <div class="home-how-scene" data-how-scene="3">
            <div class="how-phone" data-how-frame>
              <div class="how-phone-top">
                <span class="how-phone-notch"></span>
                <div class="how-phone-header">
                  <span class="how-phone-unit">Veterinary Clinic</span>
                  <span class="how-phone-pet">Milo &middot; Dog</span>
                </div>
              </div>

              <div class="how-phone-screen">
                <div class="how-subview is-visible" data-how-view="menu">
                  <div class="how-phone-label">Choose a clinic service</div>
                  <div class="how-vet-list">
                    <div class="how-vet-row" data-how-target="vet-service">
                      <div class="how-vet-row-main">
                        <span class="how-vet-name">Comprehensive Wellness Exam</span>
                        <span class="how-vet-sub tabular">30 min &middot; $48.00</span>
                      </div>
                      <span class="how-radio"></span>
                    </div>
                    <div class="how-vet-row">
                      <div class="how-vet-row-main">
                        <span class="how-vet-name">Core Vaccination Visit</span>
                        <span class="how-vet-sub tabular">20 min &middot; $38.00</span>
                      </div>
                      <span class="how-radio"></span>
                    </div>
                    <div class="how-vet-row">
                      <div class="how-vet-row-main">
                        <span class="how-vet-name">Diagnostic Wellness Panel</span>
                        <span class="how-vet-sub tabular">35 min &middot; $85.00</span>
                      </div>
                      <span class="how-radio"></span>
                    </div>
                  </div>
                </div>

                <div class="how-subview" data-how-view="schedule">
                  <div class="how-phone-label">Preferred date &amp; time</div>
                  <div class="how-date-row">
                    <span class="how-date-choice">Thu 20</span>
                    <span class="how-date-choice" data-how-target="vet-date">Fri 21</span>
                    <span class="how-date-choice">Sat 22</span>
                  </div>
                  <div class="how-time-row">
                    <span class="how-time-choice">09:30 AM</span>
                    <span class="how-time-choice" data-how-target="vet-time">11:00 AM</span>
                    <span class="how-time-choice">02:00 PM</span>
                  </div>
                  <div class="how-phone-cta-wrap">
                    <span class="how-mock-btn how-mock-btn-full" data-how-target="vet-submit">Submit request</span>
                  </div>
                </div>

                <div class="how-subview how-done-view" data-how-view="done">
                  <div class="how-done-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <div class="how-done-title">Request Submitted</div>
                  <div class="how-done-meta">Wellness Exam &middot; Fri 21, 11:00 AM</div>
                  <div class="how-done-note is-pending">Pending review</div>
                </div>
              </div>

              <div class="how-cursor" data-how-cursor>
                <span class="how-cursor-ring"></span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                  <path d="M5.5 3.5L19 12.2L12.7 13.7L9.8 19.8L5.5 3.5Z" fill="currentColor" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
              </div>
            </div>
          </div>

          <!-- Scene 4: Grooming Services (Studio Service-Selection Board) -->
          <div class="home-how-scene" data-how-scene="4">
            <div class="how-studio" data-how-frame>
              <div class="how-studio-head">
                <span class="how-studio-title">Grooming Services</span>
                <span class="how-studio-pet">Bella &middot; Dog</span>
              </div>

              <div class="how-studio-body">
                <div class="how-subview is-visible" data-how-view="builder">
                  <div class="how-studio-grid">
                    <div class="how-studio-services">
                      <div class="how-groom-card" data-how-target="groom-svc-1">
                        <span class="how-check-box"></span>
                        <div class="how-groom-info">
                          <span class="how-groom-name">Full Grooming</span>
                          <span class="how-groom-meta tabular">90 min &middot; $55.00</span>
                        </div>
                      </div>

                      <div class="how-groom-card">
                        <span class="how-check-box"></span>
                        <div class="how-groom-info">
                          <span class="how-groom-name">Basic Bath &amp; Dry</span>
                          <span class="how-groom-meta tabular">45 min &middot; $25.00</span>
                        </div>
                      </div>

                      <div class="how-groom-card" data-how-target="groom-svc-2">
                        <span class="how-check-box"></span>
                        <div class="how-groom-info">
                          <span class="how-groom-name">Nail Trim</span>
                          <span class="how-groom-meta tabular">15 min &middot; $8.00</span>
                        </div>
                      </div>
                    </div>

                    <div class="how-studio-tray">
                      <div class="how-tray-label">Preferred slot</div>
                      <div class="how-slot-list">
                        <span class="how-slot-choice">Fri 2:00 PM</span>
                        <span class="how-slot-choice" data-how-target="groom-slot">Sat 10:00 AM</span>
                      </div>
                      <div class="how-tray-summary">
                        <span data-how-groom-count>0 services</span>
                        <strong class="tabular" data-how-groom-total>$0.00</strong>
                      </div>
                      <span class="how-mock-btn how-mock-btn-full" data-how-target="groom-submit">Submit request</span>
                    </div>
                  </div>
                </div>

                <div class="how-subview how-done-view" data-how-view="done">
                  <div class="how-done-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                  </div>
                  <div class="how-done-title">Grooming Request Sent</div>
                  <div class="how-done-meta">Bella &middot; Full Grooming + Nail Trim &middot; Sat 10:00 AM</div>
                  <div class="how-done-note is-pending tabular">Est. $63.00 &middot; Pending review</div>
                </div>
              </div>

              <div class="how-cursor" data-how-cursor>
                <span class="how-cursor-ring"></span>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                  <path d="M5.5 3.5L19 12.2L12.7 13.7L9.8 19.8L5.5 3.5Z" fill="currentColor" stroke="#ffffff" stroke-width="1.6" stroke-linejoin="round"/>
                </svg>
              </div>
            </div>
          </div>

        </div>
      </div>
        </div>
      </div>
    </div>
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
                <span class="staff-unit"><?= e($rev['business_unit_name']) ?></span>
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
