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
    <div class="home-search-stage">
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
      <!-- Mobile-only Clover (Meadow Bunny — exact scenic_rails.php artwork) nibbling a carrot beside the search bar -->
      <div class="mobile-pet-companion mobile-clover-companion" data-mobile-animal="clover" aria-hidden="true">
        <svg viewBox="78 118 136 300" fill="none" xmlns="http://www.w3.org/2000/svg">
          <g class="mobile-clover-scene">
            <!-- Soft meadow ground shadow -->
            <ellipse cx="146" cy="410" rx="56" ry="8" fill="#d8eee6"/>

            <!-- Fluffy Cottontail (exact scenic_rails.php Clover) -->
            <g data-part="tail" data-pivot="180 372">
              <circle cx="192" cy="372" r="18" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
            </g>

            <!-- Bunny Breathing Body with Soft Fluffy Chest Fur (exact scenic_rails.php Clover) -->
            <g class="anim-dog-breathe" data-part="chest" data-pivot="142 396">
              <ellipse cx="142" cy="348" rx="48" ry="56" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
              <ellipse cx="142" cy="348" rx="28" ry="36" fill="#ffffff"/>
              <path d="M130 326 Q142 336 154 326 M126 344 Q142 356 158 344 M132 362 Q142 372 152 362" stroke="#cbd5e1" stroke-width="2.2" stroke-linecap="round" fill="none"/>
            </g>

            <!-- Bunny Head, Independently Articulated Living Ears, Blinking Eyes, Twitching Nose & Visible Nibbling Tooth -->
            <g data-part="head" data-pivot="142 290">
              <g class="anim-bunny-ear-left" data-part="ear-l" data-pivot="118 226">
                <ellipse cx="114" cy="176" rx="16" ry="54" transform="rotate(-10 114 176)" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
                <ellipse cx="114" cy="180" rx="8.5" ry="39" transform="rotate(-10 114 180)" fill="#f7cad0"/>
              </g>
              <g class="anim-bunny-ear-right" data-part="ear-r" data-pivot="166 226">
                <ellipse cx="170" cy="176" rx="16" ry="54" transform="rotate(10 170 176)" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
                <ellipse cx="170" cy="180" rx="8.5" ry="39" transform="rotate(10 170 180)" fill="#f7cad0"/>
              </g>

              <circle cx="142" cy="256" r="42" fill="#f1f5f9" stroke="#cbd5e1" stroke-width="2.5"/>
              <ellipse cx="112" cy="262" rx="7" ry="4" fill="#f7cad0" opacity="0.85"/>
              <ellipse cx="172" cy="262" rx="7" ry="4" fill="#f7cad0" opacity="0.85"/>

              <!-- Blinking Eyes + Eyelids (exact scenic_rails.php Clover) -->
              <g class="anim-eyes anim-eyes-1" data-part="eyes" data-pivot="142 248">
                <circle cx="124" cy="248" r="6.5" fill="#1d2a26"/>
                <circle cx="122" cy="246" r="2.2" fill="#ffffff"/>
                <circle cx="160" cy="248" r="6.5" fill="#1d2a26"/>
                <circle cx="158" cy="246" r="2.2" fill="#ffffff"/>
              </g>
              <g data-part="eyelids" opacity="0">
                <ellipse cx="124" cy="248" rx="8" ry="7.5" fill="#f1f5f9"/>
                <path d="M116 249 Q124 254 132 249" stroke="#1d2a26" stroke-width="2.3" stroke-linecap="round" fill="none"/>
                <ellipse cx="160" cy="248" rx="8" ry="7.5" fill="#f1f5f9"/>
                <path d="M152 249 Q160 254 168 249" stroke="#1d2a26" stroke-width="2.3" stroke-linecap="round" fill="none"/>
              </g>

              <!-- Resting Smile (exact scenic_rails.php Clover, shown between nibbles) -->
              <path data-part="mouth-closed" d="M133 268 Q142 275 151 268" stroke="#1d2a26" stroke-width="2.2" stroke-linecap="round" fill="none" opacity="0"/>

              <!-- Nibbling Open Mouth Cavity + Crisp White Bunny Buck Tooth -->
              <g data-part="mouth-gnaw" data-pivot="142 270">
                <g data-part="mouth-cavity" data-pivot="142 270">
                  <ellipse cx="142" cy="277.5" rx="9.5" ry="8" fill="#991b1b" stroke="#1d2a26" stroke-width="2"/>
                  <ellipse cx="142" cy="281.5" rx="5.2" ry="3" fill="#f7cad0"/>
                </g>
                <g data-part="tooth" data-pivot="142 270">
                  <rect x="135.5" y="270" width="13" height="12" rx="3" fill="#ffffff" stroke="#1d2a26" stroke-width="2"/>
                  <line x1="142" y1="270" x2="142" y2="281.5" stroke="#1d2a26" stroke-width="1.5" stroke-linecap="round"/>
                </g>
                <path d="M132 268.5 Q137 272 142 267.5 Q147 272 152 268.5" stroke="#1d2a26" stroke-width="2.1" stroke-linecap="round" fill="none"/>
              </g>

              <!-- Twitching Pink Nose & Whiskers (exact scenic_rails.php Clover) -->
              <g class="anim-nose-twitch" data-part="nose" data-pivot="142 260">
                <ellipse cx="142" cy="260" rx="7.5" ry="5.5" fill="#e07a5f"/>
                <line x1="94" y1="258" x2="122" y2="261" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
                <line x1="96" y1="266" x2="122" y2="265" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
                <line x1="190" y1="258" x2="162" y2="261" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
                <line x1="188" y1="266" x2="162" y2="265" stroke="#94a3b8" stroke-width="1.8" stroke-linecap="round"/>
              </g>
            </g>

            <!-- Crisp Carrot Held by Clover Right Up to Her Mouth -->
            <g data-part="carrot" data-pivot="142 320">
              <!-- Leafy Green Carrot Top -->
              <path d="M158 318 C166 303, 178 298, 184 304 C178 312, 168 317, 158 318 Z" fill="#52997f"/>
              <path d="M160 321 C174 313, 188 316, 191 324 C180 328, 169 326, 160 321 Z" fill="#64a68d"/>
              <path d="M157 325 C168 326, 179 334, 178 341 C169 339, 161 333, 157 325 Z" fill="#82bda7"/>
              <!-- Orange Carrot Body Angled Toward Clover's Tooth -->
              <path d="M134 286 C140 281, 158 296, 165 316 C168 325, 160 335, 150 333 C131 328, 121 302, 126 291 C128 287, 131 288, 134 286 Z" fill="#f48c42" stroke="#c85a1d" stroke-width="2.2" stroke-linejoin="round"/>
              <!-- Subtle Carrot Ridge Lines -->
              <path d="M136 298 Q144 295 148 299 M140 310 Q149 306 155 311 M146 322 Q153 319 158 323" stroke="#c85a1d" stroke-width="1.8" stroke-linecap="round" fill="none" opacity="0.72"/>
              <!-- Little Front Paws Holding the Carrot -->
              <ellipse cx="129" cy="322" rx="11.5" ry="8" transform="rotate(-18 129 322)" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
              <ellipse cx="155" cy="330" rx="11.5" ry="8" transform="rotate(14 155 330)" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
            </g>

            <!-- Bunny Sitting Feet (exact scenic_rails.php Clover) -->
            <g data-part="paws" data-pivot="142 402">
              <ellipse cx="120" cy="402" rx="14" ry="9" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
              <ellipse cx="164" cy="402" rx="14" ry="9" fill="#ffffff" stroke="#cbd5e1" stroke-width="2"/>
            </g>
          </g>
        </svg>
      </div>
    </div>
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
  <div class="section-header home-section-header home-reviews-header">
    <div class="home-reviews-heading-wrap">
      <div>
        <h2 id="home-reviews-heading">Customer Reviews</h2>
      </div>
      <a class="action-link" href="/reviews"><?= $signedIn ? 'Write a review' : 'Explore customer reviews' ?> <span class="arrow" aria-hidden="true">&rarr;</span></a>
    </div>
    <!-- Mobile-only Luna (Silver Tabby) waiting beside Explore Customer Reviews -->
    <div class="mobile-pet-companion mobile-luna-companion" data-mobile-animal="luna" aria-hidden="true">
      <svg viewBox="10 140 260 314" fill="none" xmlns="http://www.w3.org/2000/svg">
        <g class="mobile-luna-scene">
          <!-- Existing Woodland Backdrop & Oak Branch Perch from scenic_rails.php -->
          <path d="M262 392 C238 350, 206 322, 172 308" stroke="#6b4f3a" stroke-width="6" stroke-linecap="round"/>
          <path d="M44 398 C68 362, 92 336, 118 320" stroke="#7c5d46" stroke-width="5" stroke-linecap="round"/>
          <circle cx="216" cy="390" r="46" fill="#4d9078"/>
          <circle cx="68" cy="388" r="44" fill="#3b7a63"/>
          <circle cx="142" cy="372" r="58" fill="#64a68d"/>
          <ellipse cx="142" cy="432" rx="112" ry="26" fill="#cde8de"/>

          <!-- Swishing Ringed Tabby Tail (Pivots at 180, 378) -->
          <g class="anim-cat-tail" data-part="tail" data-pivot="180 378">
            <path d="M180 378 C228 364, 244 304, 214 276" stroke="#7f8c9f" stroke-width="16" stroke-linecap="round" fill="none"/>
            <path d="M214 342 L226 336 M224 316 L234 312 M220 292 L228 286" stroke="#4a5568" stroke-width="4" stroke-linecap="round"/>
          </g>

          <!-- Purring & Breathing Cat Torso (Pivots at 142, 396) -->
          <g class="anim-cat-purr" data-part="chest" data-pivot="142 396">
            <ellipse cx="142" cy="350" rx="46" ry="58" fill="#8d99ae"/>
            <path d="M104 334 Q118 338 124 332 M180 334 Q166 338 160 332 M100 354 Q116 358 122 352 M184 354 Q168 358 162 352" stroke="#5c677d" stroke-width="3.5" stroke-linecap="round" fill="none"/>
            <ellipse cx="142" cy="356" rx="26" ry="38" fill="#edf2f4"/>
          </g>

          <!-- Articulated Tabby Head, Ears, Blinking Emerald Eyes, Whiskers & Meowing Mouth (Pivots at 142, 286) -->
          <g class="anim-head-bob-alt" data-part="head" data-pivot="142 286">
            <g class="anim-ear-perk-left" data-part="ear-l" data-pivot="114 214">
              <polygon points="94,228 104,174 134,210" fill="#8d99ae"/>
              <polygon points="100,222 107,184 128,210" fill="#f7cad0"/>
            </g>
            <g class="anim-ear-perk-right" data-part="ear-r" data-pivot="170 214">
              <polygon points="190,228 180,174 150,210" fill="#8d99ae"/>
              <polygon points="184,222 177,184 156,210" fill="#f7cad0"/>
            </g>
            <circle cx="142" cy="244" r="46" fill="#8d99ae"/>
            <path d="M132 206 L136 220 M142 202 L142 218 M152 206 L148 220" stroke="#4a5568" stroke-width="3.2" stroke-linecap="round"/>

            <!-- Blinking Emerald Eyes + Eyelids -->
            <g class="anim-eyes anim-eyes-2" data-part="eyes" data-pivot="142 240">
              <circle cx="122" cy="240" r="8" fill="#a7f3d0"/>
              <ellipse cx="122" cy="240" rx="3.2" ry="6.5" fill="#1d2a26"/>
              <circle cx="119.5" cy="237" r="2.2" fill="#ffffff"/>
              <circle cx="162" cy="240" r="8" fill="#a7f3d0"/>
              <ellipse cx="162" cy="240" rx="3.2" ry="6.5" fill="#1d2a26"/>
              <circle cx="159.5" cy="237" r="2.2" fill="#ffffff"/>
            </g>
            <g data-part="eyelids" opacity="0">
              <ellipse cx="122" cy="240" rx="9.5" ry="9" fill="#8d99ae"/>
              <path d="M113 241 Q122 246 131 241" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
              <ellipse cx="162" cy="240" rx="9.5" ry="9" fill="#8d99ae"/>
              <path d="M153 241 Q162 246 171 241" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
            </g>

            <!-- Pink Nose & Articulated Meowing Mouth -->
            <polygon points="136,252 148,252 142,260" fill="#e07a5f"/>
            <path data-part="mouth-closed" d="M132 264 Q137 270 142 264 Q147 270 152 264" stroke="#1d2a26" stroke-width="2.4" stroke-linecap="round" fill="none"/>
            <g class="anim-meow-mouth" data-part="mouth-open" data-pivot="142 264" opacity="0">
              <ellipse cx="142" cy="269" rx="7.5" ry="6.5" fill="#991b1b" stroke="#1d2a26" stroke-width="2"/>
              <ellipse cx="142" cy="271" rx="5" ry="3.8" fill="#f7cad0"/>
            </g>

            <!-- Twitching Whiskers -->
            <g class="anim-whiskers" data-part="whiskers" data-pivot="142 258">
              <line x1="76" y1="248" x2="110" y2="253" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="74" y1="258" x2="110" y2="259" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="78" y1="268" x2="112" y2="265" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="208" y1="248" x2="174" y2="253" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="210" y1="258" x2="174" y2="259" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
              <line x1="206" y1="268" x2="172" y2="265" stroke="#1d2a26" stroke-width="2" stroke-linecap="round"/>
            </g>
          </g>

          <!-- Temporary Cat "Meow~" & "Purr… zzz" Dialogue Bubbles -->
          <g class="scenic-dialogue anim-dialogue-meow" data-part="dialogue-meow" data-pivot="90 190" opacity="0" style="opacity:0">
            <rect x="18" y="156" width="84" height="34" rx="17" fill="#ffffff" stroke="#0d6e57" stroke-width="2"/>
            <polygon points="82,189 96,204 70,189" fill="#ffffff"/>
            <polyline points="82,189 96,204 70,189" stroke="#0d6e57" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            <text x="60" y="178" text-anchor="middle" fill="#0d6e57" font-family="system-ui, sans-serif" font-size="13.5" font-weight="750">Meow~</text>
          </g>
          <g class="scenic-dialogue anim-dialogue-zzz" data-part="dialogue-zzz" data-pivot="190 180" opacity="0" style="opacity:0">
            <rect x="176" y="148" width="86" height="32" rx="16" fill="#ffffff" stroke="#64a68d" stroke-width="1.8"/>
            <circle cx="182" cy="190" r="4" fill="#ffffff" stroke="#64a68d" stroke-width="1.5"/>
            <circle cx="174" cy="200" r="2.5" fill="#ffffff" stroke="#64a68d" stroke-width="1.5"/>
            <text x="219" y="169" text-anchor="middle" fill="#0d6e57" font-family="system-ui, sans-serif" font-size="12.5" font-weight="700">Purr… zzz</text>
          </g>

          <!-- Oak Branch & Velvet Paws -->
          <rect x="48" y="402" width="188" height="30" rx="15" fill="#7c5d46" stroke="#5e4330" stroke-width="2"/>
          <path d="M74 414 H134 M162 420 H214" stroke="#5e4330" stroke-width="2.5" stroke-linecap="round"/>
          <ellipse cx="122" cy="402" rx="14" ry="10" fill="#edf2f4" stroke="#8d99ae" stroke-width="2"/>
          <ellipse cx="162" cy="402" rx="14" ry="10" fill="#edf2f4" stroke="#8d99ae" stroke-width="2"/>
        </g>
      </svg>
    </div>
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
