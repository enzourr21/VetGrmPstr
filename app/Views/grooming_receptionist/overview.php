<?php
$title = $title ?? 'Overview · Grooming Services Receptionist';
$curAppt = $currentAppointment ?? null;
$svcList = $services ?? [];
$curSvcPage = $servicePage ?? 1;
$totalSvcPages = $totalServicePages ?? 1;
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Grooming Services Workspace</p>
    <h1>Overview</h1>
    <p class="muted">Live overview of current grooming sessions, catalog services, daily booking summaries, and active staff presence.</p>
  </div>
</div>

<!-- SECTION A: Current / Upcoming Appointments (Top, Full Width, Exactly 1 Appointment) -->
<section class="card" style="margin-bottom: 1.5rem; padding: 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem; border-bottom: 1px solid var(--color-border, #e5e7eb); padding-bottom: 0.75rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.25rem;">Current / Upcoming Appointment</h2>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">
        <?= $curAppt && ($curAppt['status_name'] ?? '') === 'In Progress'
          ? 'Live active session currently underway in the grooming studio.'
          : 'Next scheduled appointment for today (' . date('M j, Y') . ').' ?>
      </p>
    </div>
    <?php if ($curAppt): ?>
      <span class="badge <?= e($curAppt['status_badge_class'] ?? 'badge-info') ?>" style="font-size: 0.85rem; padding: 0.35rem 0.75rem;">
        <?= e($curAppt['display_status'] ?? 'Scheduled') ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if (!$curAppt): ?>
    <div class="empty" style="padding: 2.5rem 1rem; text-align: center;">
      <div style="font-size: 2.25rem; margin-bottom: 0.5rem;" aria-hidden="true">✂️</div>
      <h3 style="margin: 0 0 0.35rem 0; font-size: 1.1rem; color: var(--color-text, #111827);">No Active or Upcoming Appointments Today</h3>
      <p class="muted" style="margin: 0; font-size: 0.9rem;">There are no grooming appointments currently in progress or scheduled for the remainder of today.</p>
      <div style="margin-top: 1rem;">
        <a href="/grooming/receptionist/appointments?tab=calendar" class="btn primary btn-sm">View Schedule &amp; Book Walk-in</a>
      </div>
    </div>
  <?php else: ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.25rem; background: var(--color-bg-subtle, #f9fafb); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb);">
      <!-- Column 1: Identification & Timing -->
      <div>
        <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Appointment Details</span>
        <div style="font-size: 1.25rem; font-weight: 700; color: var(--color-text, #111827); margin-top: 0.25rem;">
          Appointment #<?= (int)$curAppt['appointment_id'] ?>
        </div>
        <div style="margin-top: 0.5rem; display: flex; align-items: center; gap: 0.4rem; color: var(--color-text, #374151); font-weight: 500;">
          <span aria-hidden="true">🕒</span>
          <span><?= e($curAppt['time_coverage'] ?? '09:00 AM') ?></span>
        </div>
        <div class="caption muted" style="margin-top: 0.25rem;">
          Duration: ~<?= (int)($curAppt['duration_minutes'] ?? 60) ?> minutes
        </div>
      </div>

      <!-- Column 2: Pet & Customer -->
      <div>
        <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Customer &amp; Pet</span>
        <div style="font-size: 1.05rem; font-weight: 600; color: var(--color-text, #111827); margin-top: 0.25rem;">
          <?= e($curAppt['customer_display_name'] ?? 'Walk-in Guest') ?>
          <?php if (!empty($curAppt['customer_id_display'])): ?>
            <span class="caption muted">(#<?= (int)$curAppt['customer_id_display'] ?>)</span>
          <?php else: ?>
            <span class="badge" style="font-size: 0.65rem; background: #e0f2fe; color: #0369a1;">Guest</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($curAppt['customer_phone'])): ?>
          <div class="caption muted" style="margin-top: 0.25rem;">
            📞 <?= e($curAppt['customer_phone']) ?>
          </div>
        <?php endif; ?>
        <div style="margin-top: 0.5rem; font-weight: 500; color: var(--color-text, #1f2937);">
          🐾 <?= e($curAppt['pets_summary'] ?? 'Standard Grooming Pet') ?>
        </div>
      </div>

      <!-- Column 3: Requested Services -->
      <div>
        <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Services Requested</span>
        <div style="margin-top: 0.35rem; display: flex; flex-direction: column; gap: 0.3rem;">
          <?php if (!empty($curAppt['service_list'])): ?>
            <?php foreach ($curAppt['service_list'] as $sName): ?>
              <div style="font-size: 0.875rem; color: var(--color-text, #374151); display: flex; align-items: center; gap: 0.3rem;">
                <span style="color: var(--brand, #0e8a75);">✓</span>
                <span><?= e($sName) ?></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <span class="caption muted">Standard Grooming Services</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Column 4: Performer & Action -->
      <div style="display: flex; flex-direction: column; justify-content: space-between;">
        <div>
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Assigned Performer</span>
          <div style="font-size: 0.95rem; font-weight: 600; color: var(--color-text, #111827); margin-top: 0.25rem;">
            <?= e($curAppt['assigned_employee_display'] ?? 'Unassigned') ?>
          </div>
        </div>
        <div style="margin-top: 1rem;">
          <a href="/grooming/receptionist/appointments?tab=calendar" class="btn ghost btn-sm" style="width: 100%; text-align: center;">
            View in Calendar &rarr;
          </a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</section>

<!-- SECTIONS B & C: Services Card (50%) & Today's Summary Card (50%) with Matching Height -->
<div class="grid grid-2" style="margin-bottom: 1.5rem; gap: 1.25rem; align-items: stretch;">
  
  <!-- SECTION B: Services Card (Grooming Services & Prices, 3-5 paginated) -->
  <div class="card" style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; justify-content: space-between; height: 100%; box-sizing: border-box;">
    <div>
      <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 0.75rem;">
        <div>
          <h2 style="margin: 0; font-size: 1.15rem;">Grooming Services Catalog</h2>
          <p class="caption muted" style="margin: 0.15rem 0 0 0;">Active grooming care treatments and standard service rates.</p>
        </div>
        <span class="caption muted"><?= (int)$totalServices ?> services</span>
      </div>

      <?php if (empty($svcList)): ?>
        <p class="muted caption" style="padding: 1rem 0;">No active services currently listed.</p>
      <?php else: ?>
        <div style="display: flex; flex-direction: column; gap: 0.65rem; margin-top: 0.5rem;">
          <?php foreach ($svcList as $svc): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.65rem 0.85rem; background: var(--color-bg-subtle, #f9fafb); border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb);">
              <div>
                <div style="font-weight: 600; font-size: 0.9rem; color: var(--color-text, #111827);">
                  <?= e($svc['service_name']) ?>
                </div>
                <div class="caption muted" style="display: flex; gap: 0.6rem; align-items: center; margin-top: 0.15rem;">
                  <span>⏱ ~<?= (int)$svc['estimated_duration_minutes'] ?> mins</span>
                  <?php if (!empty($svc['grooming_category'])): ?>
                    <span>•</span>
                    <span><?= e($svc['grooming_category']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div style="font-weight: 700; font-size: 1rem; color: var(--brand, #0e8a75);">
                ₱<?= number_format((float)$svc['base_price'], 2) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pagination Controls -->
    <?php if ($totalSvcPages > 1): ?>
      <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border, #f3f4f6);">
        <span class="caption muted">Page <?= (int)$curSvcPage ?> of <?= (int)$totalSvcPages ?></span>
        <div style="display: flex; gap: 0.5rem;">
          <?php if ($curSvcPage > 1): ?>
            <a href="/grooming/receptionist/overview?service_page=<?= $curSvcPage - 1 ?>" class="btn ghost btn-sm">&larr; Prev</a>
          <?php endif; ?>
          <?php if ($curSvcPage < $totalSvcPages): ?>
            <a href="/grooming/receptionist/overview?service_page=<?= $curSvcPage + 1 ?>" class="btn ghost btn-sm">Next &rarr;</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- SECTION C: Today's Appointment Summary (Equal Height, Pending & Completed Counts, Bottom-Right "See Appointments" link) -->
  <div class="card" style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; justify-content: space-between; height: 100%; box-sizing: border-box;">
    <div>
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
        <div>
          <h2 style="margin: 0; font-size: 1.15rem;">Today's Appointment Summary</h2>
          <p class="caption muted" style="margin: 0.15rem 0 0 0;">Booking and fulfillment status for <?= date('F j, Y') ?>.</p>
        </div>
        <span style="font-size: 1.35rem;" aria-hidden="true">📅</span>
      </div>

      <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-top: 1rem;">
        <!-- Pending Appointments Card -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1.25rem 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); text-align: center;">
          <div style="font-size: 2.25rem; font-weight: 700; color: #d97706; line-height: 1.1;">
            <?= (int)$pendingCount ?>
          </div>
          <div class="caption muted" style="font-size: 0.8rem; font-weight: 600; margin-top: 0.35rem; text-transform: uppercase;">
            Pending Requests
          </div>
          <p class="caption muted" style="margin: 0.4rem 0 0 0; font-size: 0.75rem;">
            Awaiting receptionist review or customer response.
          </p>
        </div>

        <!-- Completed Appointments Card -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1.25rem 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); text-align: center;">
          <div style="font-size: 2.25rem; font-weight: 700; color: #059669; line-height: 1.1;">
            <?= (int)$completedCount ?>
          </div>
          <div class="caption muted" style="font-size: 0.8rem; font-weight: 600; margin-top: 0.35rem; text-transform: uppercase;">
            Completed Sessions
          </div>
          <p class="caption muted" style="margin: 0.4rem 0 0 0; font-size: 0.75rem;">
            Finished services and settled payments today.
          </p>
        </div>
      </div>
    </div>

    <!-- Bottom-right "See Appointments" link -->
    <div style="display: flex; justify-content: flex-end; align-items: center; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border, #f3f4f6);">
      <a href="/grooming/receptionist/appointments" class="btn primary btn-sm" id="see-appointments-link">
        See Appointments &rarr;
      </a>
    </div>
  </div>
</div>

<!-- SECTION D: Employees Present (According to existing employee work-status system) -->
<section class="card" style="margin-bottom: 2rem; padding: 1.25rem 1.5rem;">
  <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0; font-size: 1.15rem;">Employees Present &amp; Work Status</h2>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">Active personnel roster for Grooming Services showing current duty attendance.</p>
    </div>
    <span class="caption muted"><?= count($employeesPresent) ?> staff listed</span>
  </div>

  <?php if (empty($employeesPresent)): ?>
    <div class="empty" style="padding: 1.5rem; text-align: center;">
      <p class="muted" style="margin: 0;">No personnel recorded for Grooming Services.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x: auto; width: 100%;">
      <table style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr style="border-bottom: 1px solid var(--color-border, #e5e7eb); text-align: left; background: var(--color-bg-subtle, #f9fafb);">
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Staff Name</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Position</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: center;">Work Presence</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; text-align: center;">Today's Assignments</th>
            <th style="padding: 0.65rem 1rem; font-size: 0.75rem; font-weight: 600; text-transform: uppercase;">Contact Number</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($employeesPresent as $emp): ?>
            <?php
            $ws = (string)($emp['work_status'] ?? 'Free');
            $wsBadge = 'badge-secondary';
            if ($ws === 'On Duty') {
                $wsBadge = 'badge-success';
            } elseif ($ws === 'Free') {
                $wsBadge = 'badge-info';
            } elseif ($ws === 'Not Present') {
                $wsBadge = 'badge-danger';
            }
            ?>
            <tr style="border-bottom: 1px solid var(--color-border, #f3f4f6);">
              <td style="padding: 0.75rem 1rem; font-weight: 600; color: var(--color-text, #111827);">
                <?= e($emp['first_name'] . ' ' . $emp['last_name']) ?>
                <div class="caption muted" style="font-weight: 400;">ID #<?= (int)$emp['employee_id'] ?></div>
              </td>
              <td style="padding: 0.75rem 1rem;">
                <span class="badge" style="background: var(--color-bg-subtle, #f3f4f6); color: var(--color-text, #374151); font-weight: 500;">
                  <?= e($emp['position'] ?: 'Groomer') ?>
                </span>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: center;">
                <span class="badge <?= $wsBadge ?>" style="font-weight: 600;">
                  <?= e($ws) ?>
                </span>
              </td>
              <td style="padding: 0.75rem 1rem; text-align: center; font-weight: 600; color: var(--color-text, #1f2937);">
                <?= (int)($emp['active_assignments'] ?? 0) ?>
              </td>
              <td style="padding: 0.75rem 1rem; color: var(--color-text, #4b5563);">
                <?= e($emp['phone'] ?: '—') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

