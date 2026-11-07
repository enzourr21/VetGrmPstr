<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Operations</p>
    <h1><?= e($unitName) ?> Overview</h1>
    <p class="muted">Operational summary covering current appointment, pending refunds, stock alerts, and employees present.</p>
  </div>
</div>

<!-- ======================================================================= -->
<!-- A. TOP SECTION: WIDE CURRENT / UPCOMING APPOINTMENT CARD (Full Width)  -->
<!-- ======================================================================= -->
<section class="card grooming-wide-appointment-card" id="grooming-overview-appointment-card" style="margin-bottom: 1.5rem; width: 100%;" aria-label="Current or Upcoming Grooming Appointment">
  <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1.15rem; flex-wrap: wrap;">
    <div style="display: flex; align-items: center; gap: 0.75rem;">
      <span class="eyebrow" style="margin: 0;">Current / Upcoming Appointment</span>
      <?php if (!empty($currentAppointment)): ?>
        <strong style="font-size: 1.25rem; color: var(--ink);">#<?= (int)$currentAppointment['appointment_id'] ?></strong>
      <?php endif; ?>
    </div>
    <?php if (!empty($currentAppointment)): ?>
      <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
        <span class="tabular" style="font-size: 0.9rem; font-weight: 600; color: var(--brand);">
          <?= e($currentAppointment['formatted_date']) ?> &middot; <?= e($currentAppointment['time_coverage']) ?>
        </span>
        <?php
          $stName = (string)$currentAppointment['status_name'];
          $stBadge = $currentAppointment['badge_class'] ?? ($stName === 'Confirmed' || $stName === 'Completed'
            ? 'ok'
            : (in_array($stName, ['Cancelled', 'Rejected', 'No-Show'], true) ? 'err' : 'warn'));
        ?>
        <span class="badge <?= $stBadge ?>" style="font-weight: 700;"><?= e($stName) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if (empty($currentAppointment)): ?>
    <div class="empty" style="padding: 2.5rem 1rem;">
      No current or upcoming appointment scheduled for <?= e($unitName) ?>.
    </div>
  <?php else: ?>
    <div class="grooming-wide-appt-grid">
      <!-- 1. Customer -->
      <div class="grooming-wide-appt-col">
        <span class="caption" style="display: block; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; color: var(--ink-secondary); margin-bottom: 0.35rem;">Customer</span>
        <strong style="font-size: 1.05rem; color: var(--ink); display: block;"><?= e($currentAppointment['customer_name']) ?></strong>
        <?php if (!empty($currentAppointment['customer_phone'])): ?>
          <span class="caption" style="display: block; margin-top: 0.2rem;"><?= e($currentAppointment['customer_phone']) ?></span>
        <?php endif; ?>
      </div>

      <!-- 2. Pets Involved (Pet Name & Breed) -->
      <div class="grooming-wide-appt-col">
        <span class="caption" style="display: block; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; color: var(--ink-secondary); margin-bottom: 0.35rem;">Pets Involved</span>
        <?php if (!empty($currentAppointment['pet_items'])): ?>
          <div style="display: flex; flex-direction: column; gap: 0.35rem;">
            <?php foreach ($currentAppointment['pet_items'] as $petItem): ?>
              <div>
                <strong style="font-size: 0.95rem; color: var(--ink);"><?= e($petItem['name']) ?></strong>
                <span class="caption" style="color: var(--ink-secondary);">(<?= e($petItem['breed']) ?>)</span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <span class="caption"><?= e($currentAppointment['pet_summary']) ?></span>
        <?php endif; ?>
      </div>

      <!-- 3. Grooming Services Involved -->
      <div class="grooming-wide-appt-col">
        <span class="caption" style="display: block; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; color: var(--ink-secondary); margin-bottom: 0.35rem;">Grooming Services</span>
        <?php if (!empty($currentAppointment['service_list'])): ?>
          <div style="display: flex; flex-direction: column; gap: 0.35rem;">
            <?php foreach ($currentAppointment['service_list'] as $sName): ?>
              <div style="font-size: 0.92rem; font-weight: 600; color: var(--ink);">
                &bull; <?= e($sName) ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <span class="caption"><?= e($currentAppointment['services_summary']) ?></span>
        <?php endif; ?>
      </div>

      <!-- 4. Assigned Staff & Coverage -->
      <div class="grooming-wide-appt-col">
        <span class="caption" style="display: block; text-transform: uppercase; letter-spacing: 0.04em; font-weight: 700; color: var(--ink-secondary); margin-bottom: 0.35rem;">Assigned Staff</span>
        <strong style="font-size: 0.95rem; color: var(--ink); display: block;">
          <?= e($currentAppointment['assigned_employee'] ?: 'Unassigned') ?>
        </strong>
        <span class="caption" style="display: block; margin-top: 0.2rem;">Duration: ~<?= (int)$currentAppointment['duration_minutes'] ?> mins</span>
      </div>
    </div>

    <div style="margin-top: 1.15rem; padding-top: 0.75rem; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
      <span class="caption" style="color: var(--ink-secondary);">
        Scheduled coverage: <strong><?= e($currentAppointment['formatted_date']) ?></strong> &middot; <strong><?= e($currentAppointment['time_coverage']) ?></strong>
      </span>
      <a href="/manager/appointments<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>start=<?= e($currentAppointment['preferred_date']) ?>&select=<?= (int)$currentAppointment['appointment_id'] ?>" class="caption" style="font-weight: 600;">
        Open in Appointments Calendar &rarr;
      </a>
    </div>
  <?php endif; ?>
</section>

<!-- ======================================================================= -->
<!-- B. MIDDLE SECTION: PENDING REFUNDS & STOCK ALERTS (Equal-Height Side-by-Side Grid) -->
<!-- ======================================================================= -->
<section class="grid grid-2 grooming-overview-middle-grid" style="margin-bottom: 1.5rem; gap: 1.5rem; align-items: stretch;" aria-label="Pending Refunds and Stock Alerts">
  <!-- Left Card: Pending Refunds -->
  <article class="card grooming-overview-equal-card" id="grooming-overview-pending-refunds-card" style="overflow-x: hidden; display: flex; flex-direction: column; height: 100%;">
    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.85rem; gap: 0.5rem; flex-wrap: wrap;">
      <div>
        <h2 style="margin: 0; font-size: 1.15rem;">Pending Refunds</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Customer refund requests for <?= e($unitName) ?> awaiting review</p>
      </div>
      <strong class="stat-value" style="font-size: 1.6rem; <?= count($pendingRefunds) > 0 ? 'color: var(--err);' : '' ?>">
        <?= count($pendingRefunds) ?>
      </strong>
    </div>

    <?php if (empty($pendingRefunds)): ?>
      <div class="empty" style="padding: 1.5rem 1rem; flex: 1 1 auto; display: flex; align-items: center; justify-content: center;">No pending refund requests for <?= e($unitName) ?>.</div>
    <?php else: ?>
      <div class="grooming-refund-list" style="display: flex; flex-direction: column; gap: 0.5rem; flex: 1 1 auto; max-height: 280px; overflow-y: auto;">
        <?php foreach ($pendingRefunds as $rf): ?>
          <div style="padding: 0.65rem 0.75rem; background: var(--surface-subtle); border: 1px solid var(--line); border-radius: var(--radius-sm); font-size: 0.85rem;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 0.5rem;">
              <strong style="color: var(--ink);"><?= e($rf['refund_reference']) ?></strong>
              <strong style="color: var(--err); font-size: 0.95rem;"><?= peso((float)$rf['requested_amount']) ?></strong>
            </div>
            <div class="caption" style="margin-top: 0.2rem;">
              <span><?= e($rf['customer_name']) ?></span> &middot; <span><?= e($rf['transaction_ref']) ?></span>
            </div>
            <?php if (!empty($rf['reason'])): ?>
              <div class="caption" style="margin-top: 0.2rem; color: var(--ink-secondary);">
                Reason: <?= e($rf['reason']) ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div style="margin-top: auto; padding-top: 0.65rem; border-top: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; font-size: 0.82rem;">
      <span class="caption">Total pending: <strong><?= peso((float)$pendingRefundsTotal) ?></strong></span>
      <a href="/manager/sales<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>tab=refunds" class="caption" style="font-weight: 600;">Review Refunds &rarr;</a>
    </div>
  </article>

  <!-- Right Card: Stock Alerts -->
  <article class="card grooming-overview-equal-card" id="grooming-stock-alerts" style="overflow-x: hidden; display: flex; flex-direction: column; height: 100%;">
    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.85rem; gap: 0.5rem; flex-wrap: wrap;">
      <div>
        <h2 style="margin: 0; font-size: 1.15rem;">Stock Alerts</h2>
        <p class="caption" style="margin: 0.2rem 0 0 0;">Grooming inventory products at or below threshold (≤ 10)</p>
      </div>
      <span class="badge <?= count($stockAlerts) > 0 ? 'err' : 'ok' ?>" style="font-weight: 600;">
        <?= count($stockAlerts) ?> low stock
      </span>
    </div>

    <?php if (empty($stockAlerts)): ?>
      <div class="empty" style="padding: 1.5rem 1rem; flex: 1 1 auto; display: flex; align-items: center; justify-content: center;">No low-stock items at this time.</div>
    <?php else: ?>
      <div class="table-wrap" style="flex: 1 1 auto; max-height: 280px; overflow-y: auto;">
        <table>
          <thead>
            <tr>
              <th>Product Name</th>
              <th style="text-align: right; width: 140px;">Current Quantity</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stockAlerts as $item): ?>
              <tr>
                <td><strong><?= e($item['product_name']) ?></strong></td>
                <td class="tabular" style="text-align: right;">
                  <strong style="<?= (int)$item['on_hand'] === 0 ? 'color: var(--err);' : 'color: var(--warn);' ?>">
                    <?= (int)$item['on_hand'] ?>
                  </strong>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div style="margin-top: auto; padding-top: 0.65rem; border-top: 1px solid var(--line); display: flex; justify-content: flex-end; font-size: 0.82rem;">
      <a href="/manager/inventory<?= $unitQuery ?>" class="caption" style="font-weight: 600;">Open Inventory &rarr;</a>
    </div>
  </article>
</section>

<!-- ======================================================================= -->
<!-- C. BOTTOM SECTION: EMPLOYEES PRESENT (Full Width below middle cards)    -->
<!-- ======================================================================= -->
<section class="card" id="grooming-employees-present" style="margin-bottom: 2rem; width: 100%;" aria-label="Grooming Services Employees Present">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem; gap: 0.5rem; flex-wrap: wrap;">
    <div>
      <h2 style="margin: 0; font-size: 1.15rem;">Employees Present</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">Active Grooming Services staff prioritized by attendance &amp; operational precedence</p>
    </div>
    <a href="/manager/employees<?= $unitQuery ?>" class="caption" style="font-weight: 600;">View All Staff &rarr;</a>
  </div>

  <?php if (empty($employeesPresent)): ?>
    <div class="empty" style="padding: 1.5rem 1rem;">No employees assigned to <?= e($unitName) ?>.</div>
  <?php else: ?>
    <div class="grooming-employees-present-grid">
      <?php foreach ($employeesPresent as $emp): ?>
        <?php
          $indStatus = (string)$emp['individual_status'];
          $indBadgeClass = $indStatus === 'Free'
            ? 'ok'
            : ($indStatus === 'Occupied' ? 'warn' : 'err');
          $initials = strtoupper(substr((string)$emp['first_name'], 0, 1) . substr((string)$emp['last_name'], 0, 1));
        ?>
        <div class="grooming-employee-present-row" style="display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.85rem 1rem; border: 1px solid var(--line); border-radius: var(--radius-md); background: var(--surface-subtle);">
          <div style="display: flex; align-items: center; gap: 0.75rem; min-width: 0;">
            <div style="width: 38px; height: 38px; border-radius: 9999px; background: var(--brand-soft, #e6f4f0); color: var(--brand, #0d6e57); font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid rgba(16, 185, 129, 0.25);">
              <?= e($initials) ?>
            </div>
            <div style="min-width: 0;">
              <strong style="display: block; font-size: 0.95rem; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
              </strong>
              <span class="caption" style="display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <?= e($emp['display_position'] ?? $emp['position']) ?>
              </span>
            </div>
          </div>
          <span class="badge <?= $indBadgeClass ?>" style="flex-shrink: 0; font-weight: 700;">
            <?= e($indStatus) ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
