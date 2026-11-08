<?php
require __DIR__ . '/_nav.php';
$unitQuery = !empty($isSuper) ? '?unit_id=' . (int)$unitId : '';
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Business Manager Dashboard</p>
    <h1><?= e($unitName) ?> Overview</h1>
    <p class="muted">Clinical operations overview for <?= e($unitName) ?>: current patient appointment, clinical supplies stock alerts, daily activity, and on-duty veterinary staff.</p>
  </div>
</div>

<!-- 1. TOP SECTION: Full-Width Current / Upcoming Appointment Card -->
<section class="card" id="vet-overview-appointment-card" style="margin-bottom: 1.5rem; padding: 1.35rem 1.5rem;">
  <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 1rem;">
    <div>
      <span class="eyebrow" style="margin-bottom: 0.2rem;">Patient Consultation</span>
      <h2 style="margin: 0; font-size: 1.25rem;">Current / Upcoming Appointment</h2>
    </div>
    <?php if (!empty($currentAppointment)): ?>
      <span class="badge <?= e($currentAppointment['badge_class']) ?>">
        <?= e($currentAppointment['status_name']) ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if (!empty($currentAppointment)): ?>
    <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); border-radius: var(--radius-md); padding: 1.15rem 1.35rem;">
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem;">
        <div>
          <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.2rem;">Appointment #</span>
          <strong style="font-size: 1.1rem; color: var(--ink);">#<?= (int)$currentAppointment['appointment_id'] ?></strong>
        </div>

        <div>
          <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.2rem;">Scheduled Time</span>
          <strong style="font-size: 1.05rem;"><?= e($currentAppointment['formatted_date']) ?> · <?= e($currentAppointment['formatted_time']) ?></strong>
        </div>

        <div>
          <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.2rem;">Customer (Owner)</span>
          <strong><?= e($currentAppointment['customer_name']) ?></strong>
          <?php if (!empty($currentAppointment['customer_phone'])): ?>
            <div class="caption"><?= e($currentAppointment['customer_phone']) ?></div>
          <?php endif; ?>
        </div>

        <div>
          <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.2rem;">Patient (Pet)</span>
          <strong><?= e($currentAppointment['pet_summary']) ?></strong>
        </div>

        <div>
          <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.2rem;">Medical Services</span>
          <span><?= e($currentAppointment['services_summary'] ?: 'General Examination') ?></span>
        </div>

        <div>
          <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.2rem;">Assigned Clinician</span>
          <strong><?= e($currentAppointment['assigned_employee'] ?: 'Pending Assignment') ?></strong>
        </div>
      </div>

      <?php if (!empty($currentAppointment['notes'])): ?>
        <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--line);">
          <span class="caption" style="font-weight: 600; display: block; margin-bottom: 0.2rem;">Clinical Intake Notes:</span>
          <p class="caption" style="margin: 0; white-space: pre-line; color: var(--ink);"><?= e($currentAppointment['notes']) ?></p>
        </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="empty" style="padding: 2rem 1rem; text-align: center;">
      <p style="margin: 0; color: var(--muted);">No in-progress or upcoming medical appointments currently scheduled for <?= e($unitName) ?>.</p>
    </div>
  <?php endif; ?>
</section>

<!-- 2. MIDDLE SECTION: Two Cards Side-by-Side (Stock Alerts & Daily Clinic Activity) -->
<div class="vet-overview-middle-grid" style="margin-bottom: 1.5rem;">
  <!-- Card A: Stock Alerts -->
  <article class="card vet-overview-equal-card" id="vet-overview-stock-alerts-card" style="padding: 1.35rem 1.5rem;">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 0.85rem;">
      <div>
        <span class="eyebrow" style="margin-bottom: 0.2rem;">Inventory Monitoring</span>
        <h2 style="margin: 0; font-size: 1.15rem;">Stock Alerts</h2>
      </div>
      <span class="caption">Batch &amp; Expiry Tracked</span>
    </div>

    <p class="caption muted" style="margin: 0 0 1rem 0;">
      Veterinary Clinic pharmaceuticals and animal-health supplies running low or approaching batch expiration.
    </p>

    <?php if (empty($stockAlerts)): ?>
      <div class="empty" style="display: flex; align-items: center; justify-content: center; padding: 2rem 1rem;">
        <p style="margin: 0; color: var(--muted);">All Veterinary Clinic stock levels are adequate and batches are within valid shelf-life.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap" style="border: 1px solid var(--line); border-radius: var(--radius-sm); overflow-x: auto;">
        <table style="margin: 0; border: 0; box-shadow: none;">
          <thead>
            <tr>
              <th style="padding: 0.6rem 0.75rem;">Item</th>
              <th style="padding: 0.6rem 0.75rem;">Available</th>
              <th style="padding: 0.6rem 0.75rem;">Batch / Expiry</th>
              <th style="padding: 0.6rem 0.75rem; text-align: right;">Alert</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stockAlerts as $item): ?>
              <tr>
                <td style="padding: 0.65rem 0.75rem;">
                  <strong><?= e($item['product_name']) ?></strong>
                  <?php if (!empty($item['sku'])): ?>
                    <div class="caption"><code><?= e($item['sku']) ?></code></div>
                  <?php endif; ?>
                </td>
                <td style="padding: 0.65rem 0.75rem;">
                  <strong style="<?= (int)$item['available'] <= 0 ? 'color: var(--danger, #dc2626);' : '' ?>">
                    <?= (int)$item['available'] ?>
                  </strong>
                </td>
                <td style="padding: 0.65rem 0.75rem;">
                  <?php if (!empty($item['batch_number'])): ?>
                    <div><code><?= e($item['batch_number']) ?></code></div>
                    <span class="caption" style="color: var(--muted);"><?= e($item['expiration_date'] ? date('M j, Y', strtotime((string)$item['expiration_date'])) : 'No exp date') ?></span>
                  <?php else: ?>
                    <span class="caption" style="color: var(--muted);">Standard lot</span>
                  <?php endif; ?>
                </td>
                <td style="padding: 0.65rem 0.75rem; text-align: right;">
                  <span class="badge <?= e($item['alert_badge_class']) ?>">
                    <?= e($item['alert_label']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div style="margin-top: auto; padding-top: 1rem;">
      <a href="/manager/inventory<?= $unitQuery ?>" class="btn secondary btn-sm" id="vet-overview-open-inventory-btn">
        Open Inventory &rarr;
      </a>
    </div>
  </article>

  <!-- Card B: Daily Clinic Activity -->
  <article class="card vet-overview-equal-card" id="vet-overview-daily-activity-card" style="padding: 1.35rem 1.5rem;">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 0.85rem;">
      <div>
        <span class="eyebrow" style="margin-bottom: 0.2rem;">Today's Operations</span>
        <h2 style="margin: 0; font-size: 1.15rem;">Daily Clinic Activity</h2>
      </div>
      <span class="badge secondary" id="vet-activity-date-badge"><?= e($activityDateFormatted) ?></span>
    </div>

    <p class="caption muted" style="margin: 0 0 1rem 0;">
      Real-time consultation activity and scheduled appointments for Veterinary Clinic records on <?= e($activityDateFormatted) ?>.
    </p>

    <div class="vet-activity-body" style="display: flex; flex-direction: column; justify-content: center;">
      <div class="vet-activity-stats-row">
        <!-- Walk-in Visits Box -->
        <div class="vet-activity-stat-box" id="vet-stat-walkins">
          <span class="caption" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.35rem;">Walk-in Visits</span>
          <div class="vet-activity-stat-val" id="vet-stat-walkins-count"><?= (int)$todayWalkInCount ?></div>
          <span class="caption" style="color: var(--muted); margin-top: 0.25rem;">Clinic-local date</span>
        </div>

        <!-- Scheduled Appointments Box -->
        <div class="vet-activity-stat-box" id="vet-stat-scheduled">
          <span class="caption" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.35rem;">Scheduled Appointments</span>
          <div class="vet-activity-stat-val" id="vet-stat-scheduled-count"><?= (int)$todayScheduledCount ?></div>
          <span class="caption" style="color: var(--muted); margin-top: 0.25rem;">Medical appointments</span>
        </div>
      </div>

      <!-- Informative Schema Limitation Note -->
      <div style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 0.75rem 1rem; margin-top: 0.75rem; line-height: 1.45;">
        <p class="caption" style="margin: 0; color: var(--muted); font-size: 0.8rem;">
          <strong style="color: var(--ink);">Schema Note:</strong> The current database schema tracks medical consultations via scheduled appointment records. Unscheduled walk-in visit indicators are not supported by the database structure and remain reliably separated.
        </p>
      </div>
    </div>
  </article>
</div>

<!-- 3. BOTTOM SECTION: Full-Width Employees On Duty Card -->
<section class="card" id="vet-overview-employees-duty-card" style="padding: 1.35rem 1.5rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 0.85rem;">
    <div>
      <span class="eyebrow" style="margin-bottom: 0.2rem;">Clinical Duty Roster</span>
      <h2 style="margin: 0; font-size: 1.25rem;">Employees On Duty</h2>
    </div>
    <span class="caption">Priority: Lead/Assoc Vet &gt; Vet Nurse &gt; Vet Assistant &gt; Receptionist</span>
  </div>

  <p class="caption muted" style="margin: 0 0 1.25rem 0;">
    Clinical staff currently on duty at <?= e($unitName) ?> displayed in established clinical precedence.
  </p>

  <?php if (empty($employeesOnDuty)): ?>
    <div class="empty" style="padding: 2rem 1rem; text-align: center;">
      <p style="margin: 0; color: var(--muted);">No veterinary medical personnel are currently marked on duty.</p>
    </div>
  <?php else: ?>
    <div class="vet-employees-duty-grid">
      <?php foreach ($employeesOnDuty as $emp):
        $initials = strtoupper(substr((string)$emp['first_name'], 0, 1) . substr((string)$emp['last_name'], 0, 1));
        $ws = (string)$emp['work_status'];
        $badgeClass = $ws === 'On Duty' ? 'badge-success' : 'secondary';
      ?>
        <div class="vet-employee-duty-card">
          <div style="width: 44px; height: 44px; border-radius: 50%; background: #e0f2fe; color: #0369a1; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; flex-shrink: 0;" aria-hidden="true">
            <?= e($initials) ?>
          </div>
          <div style="flex: 1; min-width: 0;">
            <strong style="display: block; font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--ink);">
              <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
            </strong>
            <span class="caption" style="display: block; color: var(--muted); margin-bottom: 0.25rem;">
              <?= e($emp['display_position']) ?>
            </span>
            <span class="badge <?= $badgeClass ?>" style="font-size: 0.72rem;">
              <?= e($ws) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

