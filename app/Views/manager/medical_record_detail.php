<?php
require __DIR__ . '/_nav.php';

$recId = (int)$record['appointment_id'];
$recStatus = (string)$record['record_status'];
$badgeClass = $record['status_badge_class'] ?? 'secondary';
$backUrl = !empty($isSuper) && !empty($unitId) ? '/manager/units/' . (int)$unitId . '/medical-records' : '/manager/medical-records';
?>

<div class="page-head" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
  <div>
    <div style="margin-bottom: 0.35rem;">
      <a href="<?= $backUrl ?>" class="btn ghost btn-sm" style="display: inline-flex; align-items: center; gap: 0.35rem;">
        &larr; Back to Medical Records
      </a>
    </div>
    <div style="display: inline-flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem;">
      <h1 style="margin: 0;"><?= e($record['record_ref']) ?></h1>
      <span class="badge <?= $badgeClass ?>" style="padding: 0.2rem 0.65rem; font-size: 0.82rem; font-weight: 650;"><?= e($recStatus) ?></span>
    </div>
    <p class="muted" style="margin: 0.35rem 0 0 0;">
      Encounter Date: <strong><?= e($record['formatted_date']) ?></strong> at <strong><?= e($record['formatted_time']) ?></strong> · Attending: <strong><?= e($record['attending_veterinarian']) ?></strong>
    </p>
  </div>
  <div>
    <span class="badge secondary" style="font-size: 0.85rem; padding: 0.35rem 0.75rem; font-weight: 600;">
      Read-Only BM Access
    </span>
  </div>
</div>

<!-- PATIENT & ENCOUNTER CARD -->
<section class="card" style="margin-bottom: 1.5rem;">
  <h2 style="margin: 0 0 1rem 0; font-size: 1.1rem;">Patient &amp; Encounter Details</h2>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem;">
    <div>
      <span class="caption muted" style="display: block; font-size: 0.76rem; text-transform: uppercase;">Patient (Pet)</span>
      <strong style="font-size: 1rem; color: var(--ink);"><?= e($record['pet_summary']) ?></strong>
      <div class="caption muted" style="font-size: 0.82rem;"><?= e($record['pet_details_summary']) ?></div>
    </div>
    <div>
      <span class="caption muted" style="display: block; font-size: 0.76rem; text-transform: uppercase;">Client (Owner)</span>
      <strong style="font-size: 1rem; color: var(--ink);"><?= e($record['customer_name']) ?></strong>
      <div class="caption muted" style="font-size: 0.82rem;"><?= e($record['customer_phone'] ?: 'No phone recorded') ?> · <?= e($record['customer_email'] ?: 'No email recorded') ?></div>
    </div>
    <div>
      <span class="caption muted" style="display: block; font-size: 0.76rem; text-transform: uppercase;">Encounter Services</span>
      <strong style="font-size: 0.95rem; color: var(--ink);"><?= e($record['services_summary']) ?></strong>
    </div>
  </div>
</section>

<!-- SECTION 1: PHYSICAL MEASUREMENTS -->
<section class="card" style="margin-bottom: 1.5rem;">
  <h2 style="margin: 0 0 0.85rem 0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
      <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
    </svg>
    1. Physical Measurements
  </h2>
  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem; font-size: 0.9rem;">
    <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); padding: 0.75rem 1rem; border-radius: 6px;">
      <span class="caption muted" style="display: block; font-size: 0.76rem;">Body Weight</span>
      <strong style="color: var(--ink);"><?= e($record['physical_measurements']['weight']) ?></strong>
    </div>
    <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); padding: 0.75rem 1rem; border-radius: 6px;">
      <span class="caption muted" style="display: block; font-size: 0.76rem;">Rectal Temperature</span>
      <strong style="color: var(--ink);"><?= e($record['physical_measurements']['temperature']) ?></strong>
    </div>
    <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); padding: 0.75rem 1rem; border-radius: 6px;">
      <span class="caption muted" style="display: block; font-size: 0.76rem;">Heart Rate &amp; Pulse</span>
      <strong style="color: var(--ink);"><?= e($record['physical_measurements']['heart_rate']) ?></strong>
    </div>
    <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); padding: 0.75rem 1rem; border-radius: 6px;">
      <span class="caption muted" style="display: block; font-size: 0.76rem;">Hydration / CRT</span>
      <strong style="color: var(--ink);"><?= e($record['physical_measurements']['hydration']) ?></strong>
    </div>
  </div>
</section>

<!-- SECTION 2: CLINICAL ASSESSMENT (SOAP) -->
<section class="card" style="margin-bottom: 1.5rem;">
  <h2 style="margin: 0 0 0.85rem 0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
      <polyline points="14 2 14 8 20 8"></polyline>
      <line x1="16" y1="13" x2="8" y2="13"></line>
      <line x1="16" y1="17" x2="8" y2="17"></line>
      <polyline points="10 9 9 9 8 9"></polyline>
    </svg>
    2. Clinical Assessment (SOAP)
  </h2>
  <div style="display: flex; flex-direction: column; gap: 0.85rem; font-size: 0.92rem; line-height: 1.55;">
    <div>
      <strong style="color: var(--primary, #0284c7); font-size: 0.84rem; text-transform: uppercase; letter-spacing: 0.03em;">Subjective (S):</strong>
      <p style="margin: 0.2rem 0 0 0; color: var(--ink);"><?= e($record['soap']['subjective']) ?></p>
    </div>
    <div>
      <strong style="color: var(--primary, #0284c7); font-size: 0.84rem; text-transform: uppercase; letter-spacing: 0.03em;">Objective (O):</strong>
      <p style="margin: 0.2rem 0 0 0; color: var(--ink);"><?= e($record['soap']['objective']) ?></p>
    </div>
    <div>
      <strong style="color: var(--primary, #0284c7); font-size: 0.84rem; text-transform: uppercase; letter-spacing: 0.03em;">Assessment (A):</strong>
      <p style="margin: 0.2rem 0 0 0; color: var(--ink);"><?= e($record['soap']['assessment']) ?></p>
    </div>
    <div>
      <strong style="color: var(--primary, #0284c7); font-size: 0.84rem; text-transform: uppercase; letter-spacing: 0.03em;">Plan (P):</strong>
      <p style="margin: 0.2rem 0 0 0; color: var(--ink);"><?= e($record['soap']['plan']) ?></p>
    </div>
  </div>
</section>

<!-- SECTION 3: DIAGNOSTIC TESTS AND RESULTS -->
<section class="card" style="margin-bottom: 1.5rem;">
  <h2 style="margin: 0 0 0.85rem 0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
      <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
      <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
      <line x1="12" y1="22.08" x2="12" y2="12"></line>
    </svg>
    3. Diagnostic Tests &amp; Results
  </h2>
  <?php if (empty($record['diagnostic_tests'])): ?>
    <p class="caption muted" style="margin: 0; font-size: 0.9rem;">No laboratory or clinical diagnostic panels ordered for this consultation encounter.</p>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
      <?php foreach ($record['diagnostic_tests'] as $dt): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--surface-subtle, #f8fafc); padding: 0.65rem 0.85rem; border-radius: 6px; border: 1px solid var(--line);">
          <div>
            <strong style="color: var(--ink);"><?= e($dt['test_name']) ?></strong>
            <div class="caption muted" style="font-size: 0.78rem;">Ordered: <?= e($dt['ordered_date']) ?></div>
          </div>
          <span class="badge secondary" style="font-size: 0.78rem;"><?= e($dt['status']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- SECTION 4: TREATMENTS AND MEDICATION -->
<section class="card" style="margin-bottom: 1.5rem;">
  <h2 style="margin: 0 0 0.85rem 0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
      <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
      <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
    </svg>
    4. Treatments &amp; Medication
  </h2>
  <?php if (empty($record['treatments'])): ?>
    <p class="caption muted" style="margin: 0; font-size: 0.9rem;">No treatments administered during this consultation encounter.</p>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
      <?php foreach ($record['treatments'] as $tr): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; background: var(--surface-subtle, #f8fafc); padding: 0.55rem 0.85rem; border-radius: 6px; border: 1px solid var(--line);">
          <strong style="color: var(--ink);"><?= e($tr['treatment_name']) ?></strong>
          <span class="badge ok" style="font-size: 0.76rem;"><?= e($tr['status']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- SECTION 5: VETERINARIAN-ISSUED PRESCRIPTION -->
<section class="card" style="margin-bottom: 1.5rem;">
  <h2 style="margin: 0 0 0.85rem 0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem;">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
      <path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path>
      <path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path>
    </svg>
    5. Veterinarian-Issued Prescription (Rx)
  </h2>
  <?php if (empty($record['prescriptions'])): ?>
    <p class="caption muted" style="margin: 0; font-size: 0.9rem;">No prescription medications issued for this clinical encounter.</p>
  <?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.9rem;">
      <?php foreach ($record['prescriptions'] as $rx): ?>
        <div style="background: var(--surface-subtle, #f8fafc); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid var(--line);">
          <strong style="color: var(--ink); display: block; font-size: 0.95rem;"><?= e($rx['medication']) ?></strong>
          <div class="caption" style="margin: 0.25rem 0; color: var(--muted);"><?= e($rx['instructions']) ?></div>
          <div class="caption" style="color: var(--primary); font-size: 0.8rem;">Authorized by: <?= e($rx['prescribed_by']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

