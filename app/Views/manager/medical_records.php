<?php
require __DIR__ . '/_nav.php';

$actionBase = !empty($isSuper) && !empty($unitId) ? '/manager/units/' . (int)$unitId . '/medical-records' : '/manager/medical-records';
$unitQuery = !empty($isSuper) && !empty($unitId) ? '?unit_id=' . (int)$unitId : '';
$q = $searchQuery ?? '';
$stFilter = $statusFilter ?? '';
?>

<div class="page-head" style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Clinical Records</p>
    <h1>Medical Records</h1>
    <p class="muted">Chronological clinical consultation encounters, physical measurements, SOAP evaluations, diagnostic results, and veterinarian prescriptions.</p>
  </div>
  <div>
    <span class="badge secondary" style="font-size: 0.85rem; padding: 0.35rem 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem;">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      Read-Only BM Access
    </span>
  </div>
</div>

<!-- READ-ONLY COMPLIANCE NOTICE BANNER -->
<aside class="card" style="background: var(--surface-subtle, #f8fafc); border-left: 4px solid var(--primary, #0284c7); margin-bottom: 1.5rem; padding: 1rem 1.25rem;">
  <div style="display: flex; align-items: flex-start; gap: 0.75rem;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 0.15rem;" aria-hidden="true">
      <circle cx="12" cy="12" r="10"></circle>
      <line x1="12" y1="16" x2="12" y2="12"></line>
      <line x1="12" y1="8" x2="12.01" y2="8"></line>
    </svg>
    <div style="font-size: 0.88rem; line-height: 1.5; color: var(--ink);">
      <strong>Clinical Record Access Protocol:</strong> Medical records and SOAP notes are authored, updated, and signed exclusively by licensed clinical personnel (Veterinarians and Veterinary Nurses). Business Managers hold strictly read-only administrative access for regulatory compliance and operational oversight.
    </div>
  </div>
</aside>

<!-- MEDICAL RECORDS TABLE CARD -->
<section class="card" style="margin-bottom: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.85rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.15rem;">
        Clinical Patient Records (<?= count($records) ?><?= ($stFilter !== '' || $q !== '') ? ' filtered' : '' ?>)
      </h2>
      <span class="caption">Encounter records for Veterinary Clinic medical consultations. Select any record to review complete clinical details.</span>
    </div>

    <!-- Filter & Search Controls -->
    <form method="get" action="<?= $actionBase ?>" style="display: flex; gap: 0.75rem; align-items: center; margin: 0; flex-wrap: wrap;" id="records-filter-form">
      <?php if (!empty($isSuper)): ?>
        <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
      <?php endif; ?>

      <div class="search-input-wrap" style="width: 220px;">
        <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="11" cy="11" r="8"></circle>
          <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
        </svg>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search patient, client, notes... (Enter)" class="search-input" style="height: 34px;">
      </div>

      <div style="display: inline-flex; align-items: center; gap: 0.35rem;">
        <label for="filter-record-status" style="font-size: 0.88rem; font-weight: 600; color: var(--ink); margin: 0; white-space: nowrap;">Status:</label>
        <select id="filter-record-status" name="status" onchange="this.form.submit()" style="padding: 0.35rem 0.65rem; font-size: 0.88rem; border-radius: var(--radius-sm, 6px); border: 1px solid var(--line-strong); background: var(--surface); color: var(--ink);">
          <option value="" <?= $stFilter === '' ? 'selected' : '' ?>>All Statuses (<?= (int)($statusCounts['total'] ?? 0) ?>)</option>
          <option value="IN_PROGRESS" <?= $stFilter === 'IN_PROGRESS' ? 'selected' : '' ?>>IN_PROGRESS (<?= (int)($statusCounts['IN_PROGRESS'] ?? 0) ?>)</option>
          <option value="AWAITING_TEST_RESULTS" <?= $stFilter === 'AWAITING_TEST_RESULTS' ? 'selected' : '' ?>>AWAITING_TEST_RESULTS (<?= (int)($statusCounts['AWAITING_TEST_RESULTS'] ?? 0) ?>)</option>
          <option value="FINALIZED" <?= $stFilter === 'FINALIZED' ? 'selected' : '' ?>>FINALIZED (<?= (int)($statusCounts['FINALIZED'] ?? 0) ?>)</option>
          <option value="REVISED" <?= $stFilter === 'REVISED' ? 'selected' : '' ?>>REVISED (<?= (int)($statusCounts['REVISED'] ?? 0) ?>)</option>
        </select>
      </div>

      <?php if ($stFilter !== '' || $q !== ''): ?>
        <a href="<?= $actionBase . $unitQuery ?>" class="btn ghost btn-sm" id="btn-reset-filters" style="padding: 0.35rem 0.65rem; font-size: 0.85rem;" title="Reset filters">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if (empty($records)): ?>
    <div class="empty" style="text-align: center; padding: 2.5rem 1rem;">
      <p style="margin: 0 0 0.5rem 0; font-size: 1.05rem; font-weight: 600;">No medical records found</p>
      <p class="muted" style="margin: 0 0 1rem 0;">No veterinary clinical encounter records match the selected criteria.</p>
      <?php if ($stFilter !== '' || $q !== ''): ?>
        <a href="<?= $actionBase . $unitQuery ?>" class="btn secondary btn-sm">Clear Filters</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="medical-records-table">
        <thead>
          <tr>
            <th style="width: 110px; text-align: left;">Record Ref</th>
            <th style="width: 140px; text-align: left;">Date &amp; Time</th>
            <th style="min-width: 160px; text-align: left;">Patient (Pet)</th>
            <th style="min-width: 150px; text-align: left;">Client (Owner)</th>
            <th style="min-width: 160px; text-align: left;">Attending Clinician</th>
            <th style="width: 160px; text-align: center;">Clinical Status</th>
            <th style="width: 110px; text-align: right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $rec): ?>
            <?php
              $recId = (int)$rec['appointment_id'];
              $recStatus = (string)$rec['record_status'];
              $badgeClass = $rec['status_badge_class'] ?? 'secondary';
            ?>
            <tr id="medical-record-row-<?= $recId ?>">
              <td>
                <strong style="font-size: 0.88rem; color: var(--primary, #0284c7); font-family: monospace;"><?= e($rec['record_ref']) ?></strong>
              </td>
              <td style="white-space: nowrap; font-size: 0.86rem;">
                <div><?= e($rec['formatted_date']) ?></div>
                <div class="caption muted"><?= e($rec['formatted_time']) ?></div>
              </td>
              <td>
                <strong style="display: block; font-size: 0.9rem; color: var(--ink);"><?= e($rec['pet_summary']) ?></strong>
                <span class="caption muted" style="font-size: 0.78rem;"><?= e($rec['pet_details_summary']) ?></span>
              </td>
              <td>
                <strong style="display: block; font-size: 0.88rem; color: var(--ink);"><?= e($rec['customer_name']) ?></strong>
                <span class="caption muted" style="font-size: 0.78rem;"><?= e($rec['customer_phone'] ?: 'No phone') ?></span>
              </td>
              <td>
                <span style="font-size: 0.88rem; color: var(--ink); font-weight: 550;"><?= e($rec['attending_veterinarian']) ?></span>
              </td>
              <td style="text-align: center; white-space: nowrap;">
                <span class="badge <?= $badgeClass ?>" style="padding: 0.2rem 0.55rem; font-weight: 650; font-size: 0.76rem; letter-spacing: 0.02em;">
                  <?= e($recStatus) ?>
                </span>
              </td>
              <td style="text-align: right; white-space: nowrap;">
                <button type="button" class="btn secondary btn-sm"
                        data-modal-open="view-record-modal-<?= $recId ?>"
                        title="View complete read-only clinical record"
                        style="padding: 0.25rem 0.65rem; font-size: 0.8rem; font-weight: 600;">
                  View Record
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<!-- READ-ONLY CLINICAL RECORD MODALS -->
<?php foreach ($records as $rec): ?>
  <?php
    $recId = (int)$rec['appointment_id'];
    $recStatus = (string)$rec['record_status'];
    $badgeClass = $rec['status_badge_class'] ?? 'secondary';
  ?>
  <div class="modal" id="view-record-modal-<?= $recId ?>" aria-hidden="true" role="dialog" aria-labelledby="view-record-modal-title-<?= $recId ?>">
    <div class="modal-card" style="max-width: 780px; max-height: 90vh; display: flex; flex-direction: column;">
      <!-- Modal Header -->
      <div class="modal-head" style="border-bottom: 1px solid var(--line); padding-bottom: 0.85rem;">
        <div>
          <div style="display: inline-flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
            <strong style="font-family: monospace; font-size: 1.1rem; color: var(--primary, #0284c7);"><?= e($rec['record_ref']) ?></strong>
            <span class="badge <?= $badgeClass ?>" style="padding: 0.15rem 0.5rem; font-size: 0.74rem; font-weight: 650;"><?= e($recStatus) ?></span>
            <span class="badge secondary" style="font-size: 0.72rem; padding: 0.15rem 0.45rem;">Read-Only</span>
          </div>
          <h3 id="view-record-modal-title-<?= $recId ?>" style="margin: 0; font-size: 1.1rem;">
            Veterinary Clinical Encounter Record
          </h3>
          <p class="caption muted" style="margin: 0.2rem 0 0 0;">
            Encounter Date: <strong><?= e($rec['formatted_date']) ?></strong> at <strong><?= e($rec['formatted_time']) ?></strong> · Attending: <strong><?= e($rec['attending_veterinarian']) ?></strong>
          </p>
        </div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close dialog">&times;</button>
      </div>

      <!-- Modal Body (Scrollable) -->
      <div class="modal-body" style="overflow-y: auto; padding: 1rem 0; display: flex; flex-direction: column; gap: 1.25rem;">
        
        <!-- Patient & Owner Banner Grid -->
        <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 0.85rem 1rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.75rem;">
          <div>
            <span class="caption muted" style="display: block; font-size: 0.76rem; text-transform: uppercase;">Patient (Pet)</span>
            <strong style="font-size: 0.95rem; color: var(--ink);"><?= e($rec['pet_summary']) ?></strong>
            <div class="caption muted" style="font-size: 0.8rem;"><?= e($rec['pet_details_summary']) ?></div>
          </div>
          <div>
            <span class="caption muted" style="display: block; font-size: 0.76rem; text-transform: uppercase;">Client (Owner)</span>
            <strong style="font-size: 0.95rem; color: var(--ink);"><?= e($rec['customer_name']) ?></strong>
            <div class="caption muted" style="font-size: 0.8rem;"><?= e($rec['customer_phone'] ?: 'No phone recorded') ?> · <?= e($rec['customer_email'] ?: 'No email recorded') ?></div>
          </div>
          <div>
            <span class="caption muted" style="display: block; font-size: 0.76rem; text-transform: uppercase;">Encounter Services</span>
            <strong style="font-size: 0.9rem; color: var(--ink);"><?= e($rec['services_summary']) ?></strong>
          </div>
        </div>

        <!-- SECTION 1: PHYSICAL MEASUREMENTS -->
        <div style="border: 1px solid var(--line); border-radius: var(--radius-md, 8px); padding: 0.9rem 1rem;">
          <h4 style="margin: 0 0 0.65rem 0; font-size: 0.95rem; color: var(--ink); display: flex; align-items: center; gap: 0.45rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
              <path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>
            </svg>
            1. Physical Measurements
          </h4>
          <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.65rem; font-size: 0.86rem;">
            <div style="background: var(--surface); border: 1px solid var(--line-subtle, #f1f5f9); padding: 0.5rem 0.75rem; border-radius: 6px;">
              <span class="caption muted" style="display: block; font-size: 0.74rem;">Body Weight</span>
              <strong style="color: var(--ink);"><?= e($rec['physical_measurements']['weight']) ?></strong>
            </div>
            <div style="background: var(--surface); border: 1px solid var(--line-subtle, #f1f5f9); padding: 0.5rem 0.75rem; border-radius: 6px;">
              <span class="caption muted" style="display: block; font-size: 0.74rem;">Rectal Temperature</span>
              <strong style="color: var(--ink);"><?= e($rec['physical_measurements']['temperature']) ?></strong>
            </div>
            <div style="background: var(--surface); border: 1px solid var(--line-subtle, #f1f5f9); padding: 0.5rem 0.75rem; border-radius: 6px;">
              <span class="caption muted" style="display: block; font-size: 0.74rem;">Heart Rate &amp; Pulse</span>
              <strong style="color: var(--ink);"><?= e($rec['physical_measurements']['heart_rate']) ?></strong>
            </div>
            <div style="background: var(--surface); border: 1px solid var(--line-subtle, #f1f5f9); padding: 0.5rem 0.75rem; border-radius: 6px;">
              <span class="caption muted" style="display: block; font-size: 0.74rem;">Hydration / CRT</span>
              <strong style="color: var(--ink);"><?= e($rec['physical_measurements']['hydration']) ?></strong>
            </div>
          </div>
        </div>

        <!-- SECTION 2: CLINICAL ASSESSMENT (SOAP) -->
        <div style="border: 1px solid var(--line); border-radius: var(--radius-md, 8px); padding: 0.9rem 1rem;">
          <h4 style="margin: 0 0 0.65rem 0; font-size: 0.95rem; color: var(--ink); display: flex; align-items: center; gap: 0.45rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
              <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            2. Clinical Assessment (SOAP)
          </h4>
          <div style="display: flex; flex-direction: column; gap: 0.65rem; font-size: 0.86rem; line-height: 1.5;">
            <div>
              <strong style="color: var(--primary, #0284c7); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em;">Subjective (S):</strong>
              <p style="margin: 0.15rem 0 0 0; color: var(--ink);"><?= e($rec['soap']['subjective']) ?></p>
            </div>
            <div>
              <strong style="color: var(--primary, #0284c7); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em;">Objective (O):</strong>
              <p style="margin: 0.15rem 0 0 0; color: var(--ink);"><?= e($rec['soap']['objective']) ?></p>
            </div>
            <div>
              <strong style="color: var(--primary, #0284c7); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em;">Assessment (A):</strong>
              <p style="margin: 0.15rem 0 0 0; color: var(--ink);"><?= e($rec['soap']['assessment']) ?></p>
            </div>
            <div>
              <strong style="color: var(--primary, #0284c7); font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.03em;">Plan (P):</strong>
              <p style="margin: 0.15rem 0 0 0; color: var(--ink);"><?= e($rec['soap']['plan']) ?></p>
            </div>
          </div>
        </div>

        <!-- SECTION 3: DIAGNOSTIC TESTS AND RESULTS -->
        <div style="border: 1px solid var(--line); border-radius: var(--radius-md, 8px); padding: 0.9rem 1rem;">
          <h4 style="margin: 0 0 0.65rem 0; font-size: 0.95rem; color: var(--ink); display: flex; align-items: center; gap: 0.45rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
              <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
              <line x1="12" y1="22.08" x2="12" y2="12"></line>
            </svg>
            3. Diagnostic Tests &amp; Results
          </h4>
          <?php if (empty($rec['diagnostic_tests'])): ?>
            <p class="caption muted" style="margin: 0; font-size: 0.86rem;">No laboratory or clinical diagnostic panels ordered for this consultation encounter.</p>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.86rem;">
              <?php foreach ($rec['diagnostic_tests'] as $dt): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--surface); padding: 0.5rem 0.75rem; border-radius: 6px; border: 1px solid var(--line);">
                  <div>
                    <strong style="color: var(--ink);"><?= e($dt['test_name']) ?></strong>
                    <div class="caption muted" style="font-size: 0.76rem;">Ordered: <?= e($dt['ordered_date']) ?></div>
                  </div>
                  <span class="badge secondary" style="font-size: 0.76rem;"><?= e($dt['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- SECTION 4: TREATMENTS AND MEDICATION -->
        <div style="border: 1px solid var(--line); border-radius: var(--radius-md, 8px); padding: 0.9rem 1rem;">
          <h4 style="margin: 0 0 0.65rem 0; font-size: 0.95rem; color: var(--ink); display: flex; align-items: center; gap: 0.45rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
              <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
              <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
            </svg>
            4. Treatments &amp; Medication
          </h4>
          <?php if (empty($rec['treatments'])): ?>
            <p class="caption muted" style="margin: 0; font-size: 0.86rem;">No treatments administered during this consultation encounter.</p>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.86rem;">
              <?php foreach ($rec['treatments'] as $tr): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; background: var(--surface); padding: 0.45rem 0.75rem; border-radius: 6px; border: 1px solid var(--line);">
                  <strong style="color: var(--ink);"><?= e($tr['treatment_name']) ?></strong>
                  <span class="badge ok" style="font-size: 0.74rem;"><?= e($tr['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- SECTION 5: OPTIONAL VETERINARIAN-ISSUED PRESCRIPTION -->
        <div style="border: 1px solid var(--line); border-radius: var(--radius-md, 8px); padding: 0.9rem 1rem;">
          <h4 style="margin: 0 0 0.65rem 0; font-size: 0.95rem; color: var(--ink); display: flex; align-items: center; gap: 0.45rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--primary);" aria-hidden="true">
              <path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"></path>
              <path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"></path>
            </svg>
            5. Veterinarian-Issued Prescription (Rx)
          </h4>
          <?php if (empty($rec['prescriptions'])): ?>
            <p class="caption muted" style="margin: 0; font-size: 0.86rem;">No prescription medications issued for this clinical encounter.</p>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 0.4rem; font-size: 0.86rem;">
              <?php foreach ($rec['prescriptions'] as $rx): ?>
                <div style="background: var(--surface); padding: 0.6rem 0.85rem; border-radius: 6px; border: 1px solid var(--line);">
                  <strong style="color: var(--ink); display: block;"><?= e($rx['medication']) ?></strong>
                  <div class="caption" style="margin: 0.2rem 0; color: var(--muted);"><?= e($rx['instructions']) ?></div>
                  <div class="caption" style="color: var(--primary); font-size: 0.78rem;">Authorized by: <?= e($rx['prescribed_by']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>

      <!-- Modal Footer -->
      <div class="modal-foot" style="border-top: 1px solid var(--line); padding-top: 0.85rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
        <span class="caption muted" style="font-size: 0.78rem;">
          Read-only clinical record snapshot. Clinical alterations or signature revisions require licensed veterinarian credentials.
        </span>
        <button type="button" class="btn secondary btn-sm" data-modal-close>Close</button>
      </div>
    </div>
  </div>
<?php endforeach; ?>

