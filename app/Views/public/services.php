<?php $signedIn = (bool)\App\Core\Session::get('customer_id'); ?>
<div class="page-header">
  <div>
    <h1>Veterinary &amp; Grooming Services</h1>
    <p class="muted">Explore professional services, descriptions, durations, and transparent rates across our Veterinary Clinic and Grooming Services studio.</p>
  </div>
  <div class="row">
    <div class="segmented-bar" role="group" aria-label="Filter service category">
      <button type="button" class="segmented-pill is-active" data-service-filter="all" aria-pressed="true">All (<?= count($vetServices) + count($groomingServices) ?>)</button>
      <button type="button" class="segmented-pill" data-service-filter="veterinary" aria-pressed="false">Veterinary (<?= count($vetServices) ?>)</button>
      <button type="button" class="segmented-pill" data-service-filter="grooming" aria-pressed="false">Grooming (<?= count($groomingServices) ?>)</button>
    </div>
    <?php if ($signedIn): ?>
      <a class="btn" href="/appointments/new">Request Grooming Appointment</a>
    <?php else: ?>
      <a class="btn" href="/login">Sign in to Book Grooming</a>
    <?php endif; ?>
  </div>
</div>

<section id="veterinary" style="margin-bottom: 2.75rem;">
  <div class="section-header" style="margin-top: 0.5rem;">
    <div>
      <h2>Veterinary Clinic</h2>
      <p class="muted">Preventive consultations, diagnostics, vaccinations, and clinical dental care.</p>
    </div>
  </div>
  <div class="grid">
    <?php foreach ($vetServices as $s): ?>
      <article class="card overview-card">
        <div>
          <div class="row" style="justify-content: space-between; margin-bottom: 0.45rem;">
            <span class="caption">Veterinary Clinic</span>
            <span class="caption tabular"><?= (int)$s['estimated_duration_minutes'] ?> mins</span>
          </div>
          <h3><?= e($s['service_name']) ?></h3>
          <p class="muted" style="margin: 0.4rem 0 0;"><?= e($s['description']) ?></p>
        </div>
        <div class="overview-card-footer">
          <span class="price"><?= money($s['base_price']) ?></span>
          <span class="caption">Clinical consultation</span>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<section id="grooming">
  <div class="section-header">
    <div>
      <h2>Grooming Services</h2>
      <p class="muted">Coat care, bathing, styling, and hygiene treatments tailored to your pet.</p>
    </div>
  </div>
  <div class="grid">
    <?php foreach ($groomingServices as $s): ?>
      <article class="card overview-card">
        <div>
          <div class="row" style="justify-content: space-between; margin-bottom: 0.45rem;">
            <span class="caption">Grooming Services</span>
            <span class="caption tabular"><?= (int)$s['estimated_duration_minutes'] ?> mins</span>
          </div>
          <h3><?= e($s['service_name']) ?></h3>
          <p class="muted" style="margin: 0.4rem 0 0;"><?= e($s['description']) ?></p>
        </div>
        <div class="overview-card-footer">
          <span class="price"><?= money($s['base_price']) ?></span>
          <a class="action-link" href="<?= $signedIn ? '/appointments/new' : '/login' ?>">Request service <span class="arrow" aria-hidden="true">&rarr;</span></a>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
