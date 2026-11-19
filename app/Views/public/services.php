<?php $signedIn = (bool)\App\Core\Session::get('customer_id'); ?>
<div class="page-header services-page-header">
  <div>
    <h1>Veterinary &amp; Grooming Services</h1>
  </div>
  <div class="services-controls">
    <div class="tabs" role="group" aria-label="Filter service category">
      <button type="button" class="tab-link is-active" data-service-filter="all" aria-pressed="true">All (<?= count($vetServices) + count($groomingServices) ?>)</button>
      <button type="button" class="tab-link" data-service-filter="veterinary" aria-pressed="false">Veterinary (<?= count($vetServices) ?>)</button>
      <button type="button" class="tab-link" data-service-filter="grooming" aria-pressed="false">Grooming (<?= count($groomingServices) ?>)</button>
    </div>
    <?php if ($signedIn): ?>
      <a class="btn" href="/appointments/new">Request Grooming Appointment</a>
    <?php endif; ?>
  </div>
</div>

<section id="veterinary" class="service-section" aria-labelledby="vet-services-heading">
  <div class="service-section-header">
    <div class="service-section-title">
      <span class="service-section-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/>
          <path d="M8 15v1a6 6 0 0 0 6 6v0a6 6 0 0 0 6-6v-4"/>
          <circle cx="20" cy="10" r="2"/>
        </svg>
      </span>
      <h2 id="vet-services-heading">Veterinary Clinic</h2>
    </div>
    <span class="caption tabular"><?= count($vetServices) ?> service<?= count($vetServices) === 1 ? '' : 's' ?></span>
  </div>
  <?php if (!$vetServices): ?>
    <div class="empty-subtle">No veterinary services are currently available.</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($vetServices as $s): ?>
        <article class="card overview-card service-card">
          <div>
            <div class="service-card-head">
              <h3><?= e($s['service_name']) ?></h3>
              <?php if ((int)$s['estimated_duration_minutes'] > 0): ?>
                <span class="service-duration tabular" aria-label="Estimated duration <?= (int)$s['estimated_duration_minutes'] ?> minutes">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                  </svg>
                  <?= (int)$s['estimated_duration_minutes'] ?> mins
                </span>
              <?php endif; ?>
            </div>
            <?php if (!empty($s['description'])): ?>
              <p class="service-desc"><?= e($s['description']) ?></p>
            <?php endif; ?>
          </div>
          <div class="overview-card-footer">
            <span class="price service-price"><?= money($s['base_price']) ?></span>
            <?php if (!$signedIn): ?>
              <a class="action-link service-book-link" href="/login" aria-label="Sign in to book <?= e($s['service_name']) ?>">
                Sign in to book <span class="arrow" aria-hidden="true">&rarr;</span>
              </a>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section id="grooming" class="service-section" aria-labelledby="grooming-services-heading">
  <div class="service-section-header">
    <div class="service-section-title">
      <span class="service-section-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="6" cy="6" r="3"/>
          <circle cx="6" cy="18" r="3"/>
          <line x1="20" y1="4" x2="8.12" y2="15.88"/>
          <line x1="14.47" y1="14.48" x2="20" y2="20"/>
          <line x1="8.12" y1="8.12" x2="12" y2="12"/>
        </svg>
      </span>
      <h2 id="grooming-services-heading">Grooming Services</h2>
    </div>
    <span class="caption tabular"><?= count($groomingServices) ?> service<?= count($groomingServices) === 1 ? '' : 's' ?></span>
  </div>
  <?php if (!$groomingServices): ?>
    <div class="empty-subtle">No grooming services are currently available.</div>
  <?php else: ?>
    <div class="grid">
      <?php foreach ($groomingServices as $s): ?>
        <article class="card overview-card service-card">
          <div>
            <div class="service-card-head">
              <h3><?= e($s['service_name']) ?></h3>
              <?php if ((int)$s['estimated_duration_minutes'] > 0): ?>
                <span class="service-duration tabular" aria-label="Estimated duration <?= (int)$s['estimated_duration_minutes'] ?> minutes">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                  </svg>
                  <?= (int)$s['estimated_duration_minutes'] ?> mins
                </span>
              <?php endif; ?>
            </div>
            <?php if (!empty($s['description'])): ?>
              <p class="service-desc"><?= e($s['description']) ?></p>
            <?php endif; ?>
          </div>
          <div class="overview-card-footer">
            <span class="price service-price"><?= money($s['base_price']) ?></span>
            <a class="action-link service-book-link" href="<?= $signedIn ? '/appointments/new' : '/login' ?>" aria-label="<?= $signedIn ? 'Request ' : 'Sign in to request ' ?><?= e($s['service_name']) ?>">
              <?= $signedIn ? 'Request service' : 'Sign in to book' ?> <span class="arrow" aria-hidden="true">&rarr;</span>
            </a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
