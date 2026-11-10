<?php
$title = $title ?? 'Appointments · Veterinary Clinic Receptionist';
$activeTab = $activeTab ?? 'requests';
$reqList = $requests ?? [];
$svcs = $medicalServices ?? [];
$cliniciansList = $clinicians ?? [];
$wkOffset = $weekOffset ?? 0;
$weekDays = $weekDays ?? [];
$rangeHeading = $rangeHeading ?? '7-Day Schedule';
$monthPickerValue = $monthPickerValue ?? date('Y-m');
$isCurrentWeek = $isCurrentWeek ?? true;
$prevWeekStart = $prevWeekStart ?? date('Y-m-d', strtotime('-7 days'));
$nextWeekStart = $nextWeekStart ?? date('Y-m-d', strtotime('+7 days'));
$todayWeekStart = $todayWeekStart ?? date('Y-m-d');
$gridStartHour = $gridStartHour ?? 8;
$gridEndHour = $gridEndHour ?? 18;
$gridHeightPx = $gridHeightPx ?? 660;
$appointmentsByDate = $appointmentsByDate ?? [];
$allVisibleAppointments = $allVisibleAppointments ?? [];
$selectedApptId = $selectedApptId ?? null;
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Veterinary Clinic Workspace</p>
    <h1>Appointments</h1>
    <p class="muted">Review incoming customer medical consultation requests, manage the 7-day clinic schedule, and register walk-in visits.</p>
  </div>
</div>

<!-- Navigation Tabs: Exactly 2 Tabs -->
<div class="tabs-nav" style="display: flex; gap: 0.5rem; border-bottom: 2px solid var(--color-border, #e5e7eb); margin-bottom: 1.5rem;">
  <a href="/vetclinic/receptionist/appointments?tab=requests"
     class="tab-link <?= $activeTab === 'requests' ? 'is-active' : '' ?>"
     style="padding: 0.65rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === 'requests' ? 'var(--brand, #0e8a75)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $activeTab === 'requests' ? 'var(--brand, #0e8a75)' : 'var(--color-muted, #6b7280)' ?>;">
    Appointment Requests
    <?php if (count($reqList) > 0): ?>
      <span class="badge badge-warning" style="margin-left: 0.4rem; font-size: 0.75rem; padding: 0.15rem 0.45rem;">
        <?= count($reqList) ?>
      </span>
    <?php endif; ?>
  </a>
  <a href="/vetclinic/receptionist/appointments?tab=calendar"
     class="tab-link <?= $activeTab === 'calendar' ? 'is-active' : '' ?>"
     style="padding: 0.65rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === 'calendar' ? 'var(--brand, #0e8a75)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $activeTab === 'calendar' ? 'var(--brand, #0e8a75)' : 'var(--color-muted, #6b7280)' ?>;">
    Appointments Calendar
  </a>
</div>

<?php if ($activeTab === 'requests'): ?>
  <!-- ========================================================================= -->
  <!-- TAB 1: APPOINTMENT REQUESTS                                                -->
  <!-- ========================================================================= -->
  <section class="card" style="padding: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <div>
        <h2 style="margin: 0; font-size: 1.25rem;">Incoming Appointment Requests (<?= count($reqList) ?>)</h2>
        <p class="caption muted" style="margin: 0.2rem 0 0 0;">
          Review online medical bookings from registered customers. Accept the schedule or propose an alternative consultation time.
        </p>
      </div>
    </div>

    <?php if (empty($reqList)): ?>
      <div class="empty" style="padding: 3rem 1rem; text-align: center;">
        <div style="font-size: 2.25rem; margin-bottom: 0.5rem;" aria-hidden="true">📬</div>
        <h3 style="margin: 0 0 0.35rem 0; font-size: 1.1rem; color: var(--color-text, #111827);">No Pending Requests</h3>
        <p class="muted" style="margin: 0; font-size: 0.9rem;">All customer booking requests have been processed. Switch to the Calendar tab to review current bookings.</p>
        <div style="margin-top: 1rem;">
          <a href="/vetclinic/receptionist/appointments?tab=calendar" class="btn ghost btn-sm">Open Appointments Calendar</a>
        </div>
      </div>
    <?php else: ?>
      <div style="display: flex; flex-direction: column; gap: 1rem;">
        <?php foreach ($reqList as $req): ?>
          <div class="card" style="padding: 1.25rem; border: 1px solid var(--color-border, #e5e7eb); background: var(--color-bg-subtle, #f9fafb); border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem;">
              <div>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                  <span style="font-size: 1.15rem; font-weight: 700; color: var(--color-text, #111827);">
                    Request #<?= (int)$req['appointment_id'] ?>
                  </span>
                  <span class="badge <?= e($req['status_badge_class'] ?? 'badge-warning') ?>">
                    <?= e($req['display_status'] ?? 'Pending') ?>
                  </span>
                </div>
                <div class="caption muted" style="margin-top: 0.25rem;">
                  Submitted on <?= date('M j, Y g:i A', strtotime($req['created_at'])) ?>
                </div>
              </div>

              <div style="text-align: right;">
                <div style="font-size: 1.05rem; font-weight: 700; color: var(--brand, #0e8a75);">
                  ₱<?= number_format((float)$req['total_service_cost'], 2) ?>
                </div>
                <div class="caption muted">Est. Duration: ~<?= (int)$req['duration_minutes'] ?> mins</div>
              </div>
            </div>

            <!-- Details Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border, #e5e7eb);">
              <div>
                <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Customer Information</span>
                <div style="font-weight: 600; color: var(--color-text, #111827); margin-top: 0.2rem;">
                  <?= e($req['customer_display_name']) ?>
                  <?php if (!empty($req['customer_id_display'])): ?>
                    <span class="caption muted">(#<?= (int)$req['customer_id_display'] ?>)</span>
                  <?php endif; ?>
                </div>
                <div class="caption muted">📞 <?= e($req['customer_phone'] ?: 'No phone') ?></div>
                <?php if (!empty($req['cust_email'])): ?>
                  <div class="caption muted">✉️ <?= e($req['cust_email']) ?></div>
                <?php endif; ?>
              </div>

              <div>
                <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Registered Pets</span>
                <div style="margin-top: 0.2rem; font-weight: 500;">
                  🐾 <?= e($req['pets_summary']) ?>
                </div>
              </div>

              <div>
                <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Requested Medical Services</span>
                <div style="margin-top: 0.2rem; display: flex; flex-direction: column; gap: 0.2rem;">
                  <?php foreach ($req['service_list'] as $sName): ?>
                    <div style="font-size: 0.85rem; color: var(--color-text, #374151);">
                      ✓ <?= e($sName) ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <div>
                <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Preferred Schedule</span>
                <div style="font-weight: 600; color: var(--color-text, #111827); margin-top: 0.2rem;">
                  📅 <?= date('M j, Y (D)', strtotime($req['preferred_date'])) ?>
                </div>
                <div style="font-size: 0.9rem; color: var(--color-text, #374151); margin-top: 0.15rem;">
                  🕒 <?= date('g:i A', strtotime($req['preferred_time'] ?: '09:00:00')) ?>
                </div>
              </div>
            </div>

            <?php if (!empty($req['notes'])): ?>
              <div style="margin-top: 0.75rem; padding: 0.65rem 0.85rem; background: #fff; border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb); font-size: 0.85rem;">
                <span style="font-weight: 600; color: var(--color-muted, #4b5563);">Notes / History:</span>
                <span style="color: var(--color-text, #374151);"><?= nl2br(e($req['notes'])) ?></span>
              </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div style="display: flex; justify-content: flex-end; align-items: center; gap: 0.6rem; margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border, #e5e7eb);">
              <!-- Accept Form -->
              <form method="post" action="/vetclinic/receptionist/appointments/requests/<?= (int)$req['appointment_id'] ?>/accept" onsubmit="return confirm('Accept and confirm appointment #<?= (int)$req['appointment_id'] ?> for <?= e($req['preferred_date']) ?> at <?= date('g:i A', strtotime($req['preferred_time'] ?: '09:00:00')) ?>?');">
                <?= \App\Core\CSRF::field() ?>
                <button type="submit" class="btn primary btn-sm">
                  ✓ Accept &amp; Confirm
                </button>
              </form>

              <!-- Propose Alternative Button (Opens Modal) -->
              <button type="button" class="btn ghost btn-sm" onclick="openProposeModal(<?= (int)$req['appointment_id'] ?>, '<?= e($req['preferred_date']) ?>', '<?= e($req['preferred_time'] ? substr($req['preferred_time'], 0, 5) : '09:00') ?>')">
                ⏱ Propose Alternative Time
              </button>

              <!-- Decline Form -->
              <form method="post" action="/vetclinic/receptionist/appointments/requests/<?= (int)$req['appointment_id'] ?>/reject" onsubmit="return confirm('Decline Veterinary Clinic appointment request #<?= (int)$req['appointment_id'] ?>?');">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="reason" value="Clinical scheduling unavailability">
                <button type="submit" class="btn ghost btn-sm" style="color: #dc2626;">
                  ✕ Decline
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

<?php else: ?>
  <!-- ========================================================================= -->
  <!-- TAB 2: APPOINTMENTS CALENDAR (7-Day Schedule with Overlap Layout)         -->
  <!-- ========================================================================= -->
  <!-- Calendar Navigation Toolbar Matching BM Structure -->
  <div class="card grooming-cal-toolbar" style="padding: 0.75rem 1.25rem; margin-bottom: 0.85rem;">
    <div class="grooming-cal-nav-row">
      <!-- Far Left: Previous 7-Day Range (arrow only: ‹) -->
      <a href="/vetclinic/receptionist/appointments?tab=calendar&start=<?= e($prevWeekStart) ?>"
         class="btn secondary btn-sm grooming-cal-nav-btn"
         id="grooming-cal-prev"
         title="Previous 7 days"
         aria-label="Previous 7 days">&lsaquo;</a>

      <!-- Center: Today button, Active 7-Day Range Label, Max Overlap badge, and Month Picker -->
      <div class="grooming-cal-nav-center">
        <a href="/vetclinic/receptionist/appointments?tab=calendar&start=<?= e($todayWeekStart) ?>"
           class="btn <?= $isCurrentWeek ? 'secondary' : 'ghost' ?> btn-sm grooming-cal-today-btn"
           id="grooming-cal-today">
          Today
        </a>

        <strong class="grooming-cal-range-label" id="grooming-cal-range-label">
          <?= e($rangeHeading) ?>
        </strong>
        <span class="badge secondary" style="font-size: 0.76rem; font-weight: 600;" id="vet-cal-overlap-badge" title="Maximum allowed appointment overlap configured for Veterinary Clinic">
          Max Overlap: <?= (int)($maxOverlapMinutes ?? 15) ?>m
        </span>

        <form method="get" action="/vetclinic/receptionist/appointments" class="grooming-cal-month-form" id="grooming-cal-month-form">
          <input type="hidden" name="tab" value="calendar">
          <input type="month"
                 id="grooming-cal-month-picker"
                 name="month"
                 value="<?= e($monthPickerValue) ?>"
                 title="Select month to start calendar at first day"
                 aria-label="Select month to start calendar at first day"
                 class="grooming-cal-month-input">
          <noscript><button type="submit" class="btn ghost btn-sm">Go</button></noscript>
        </form>
      </div>

      <!-- Far Right: Next 7-Day Range (arrow: ›) & + Walk-in Appointment Button -->
      <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="/vetclinic/receptionist/appointments?tab=calendar&start=<?= e($nextWeekStart) ?>"
           class="btn secondary btn-sm grooming-cal-nav-btn"
           id="grooming-cal-next"
           title="Next 7 days"
           aria-label="Next 7 days">&rsaquo;</a>

        <button type="button" class="btn primary btn-sm" id="open-walkin-btn" onclick="openWalkinModal()">
          + Walk-in Appointment
        </button>
      </div>
    </div>
  </div>

  <!-- Google Calendar-inspired 7-Day Weekly Calendar Matching BM -->
  <div class="card grooming-weekly-calendar-card" id="grooming-weekly-calendar-card">
    <!-- 7-Day Column Header Row -->
    <div class="grooming-cal-header-grid">
      <div class="grooming-cal-time-gutter-head">GMT+8</div>
      <?php foreach ($weekDays as $dayInfo): ?>
        <div class="grooming-cal-day-head <?= !empty($dayInfo['is_today']) ? 'is-today' : '' ?>" data-date="<?= e($dayInfo['date']) ?>">
          <span class="grooming-cal-day-name"><?= e($dayInfo['day_short'] ?? $dayInfo['day_name'] ?? '') ?></span>
          <strong class="grooming-cal-day-num <?= !empty($dayInfo['is_today']) ? 'is-today-pill' : '' ?>">
            <?= e($dayInfo['day_label'] ?? (($dayInfo['month_short'] ?? '') . ' ' . ($dayInfo['day_num'] ?? ''))) ?>
          </strong>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Vertical Time Grid + 7 Day Columns -->
    <div class="grooming-cal-body-wrap" id="grooming-cal-body-wrap">
      <div class="grooming-cal-body-grid" style="height: <?= (int)$gridHeightPx ?>px;">
        <!-- Left Time Axis Gutter -->
        <div class="grooming-cal-time-gutter">
          <?php for ($hour = $gridStartHour; $hour <= $gridEndHour; $hour++): ?>
            <?php
              $topPx = ($hour - $gridStartHour) * 60;
              $label = date('g A', mktime($hour, 0, 0));
            ?>
            <div class="grooming-cal-time-label" style="top: <?= (int)$topPx ?>px;">
              <span><?= e($label) ?></span>
            </div>
          <?php endfor; ?>
        </div>

        <!-- 7 Chronological Day Columns -->
        <?php foreach ($weekDays as $dayIndex => $dayInfo): ?>
          <?php
            $dateKey = $dayInfo['date'];
            $dayAppts = $appointmentsByDate[$dateKey] ?? [];
          ?>
          <div class="grooming-cal-day-col <?= $dayInfo['is_today'] ? 'is-today-col' : '' ?>"
               data-date="<?= e($dateKey) ?>"
               data-day-index="<?= (int)$dayIndex ?>">
            <!-- Hourly Horizontal Guide Lines -->
            <?php for ($hour = $gridStartHour; $hour < $gridEndHour; $hour++): ?>
              <div class="grooming-cal-hour-cell" style="top: <?= (int)(($hour - $gridStartHour) * 60) ?>px; height: 60px;"></div>
            <?php endfor; ?>

            <!-- Appointment Blocks Positioned by Time, Duration & Overlap Sub-Column -->
            <?php foreach ($dayAppts as $appt): ?>
              <?php
                $apptId = (int)$appt['appointment_id'];
                $isSelected = ($selectedApptId === $apptId);
                $totalCols = max(1, (int)$appt['overlap_total_cols']);
                $colIdx = (int)$appt['overlap_col_index'];
                $widthPct = round(100 / $totalCols, 4);
                $leftPct = round($colIdx * (100 / $totalCols), 4);
              ?>
              <button type="button"
                      class="grooming-cal-appt-block <?= e($appt['color_class']) ?> <?= $isSelected ? 'is-selected' : '' ?>"
                      id="grooming-appt-block-<?= $apptId ?>"
                      data-appt-id="<?= $apptId ?>"
                      data-day-index="<?= (int)$dayIndex ?>"
                      data-status="<?= e($appt['status_name']) ?>"
                      data-status-color="<?= e($appt['status_color_name']) ?>"
                      style="top: <?= (int)$appt['top_px'] ?>px; height: <?= (int)$appt['height_px'] ?>px; left: calc(<?= $leftPct ?>% + 2px); width: calc(<?= $widthPct ?>% - 4px);"
                      aria-expanded="<?= $isSelected ? 'true' : 'false' ?>"
                      aria-controls="grooming-speech-bubble-<?= $apptId ?>"
                      title="Appt #<?= $apptId ?>: <?= e($appt['customer_display_name']) ?> (<?= e($appt['time_coverage']) ?>)">
                <strong class="grooming-cal-block-id">#<?= $apptId ?><?= !empty($appt['customer_display_name']) ? ' · ' . e($appt['customer_display_name']) : '' ?></strong>
                <span class="grooming-cal-block-time"><?= e($appt['time_coverage']) ?></span>
                <?php if (!empty($appt['pets_summary']) && $appt['pets_summary'] !== 'No pet recorded'): ?>
                  <span class="grooming-cal-block-customer">🐾 <?= e($appt['pets_summary']) ?></span>
                <?php endif; ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Speech-Bubble Dialogue Boxes (One per appointment in the visible 7-day range, outside columns to avoid clipping) -->
      <?php foreach ($allVisibleAppointments as $appt): ?>
        <?php
          $apptId = (int)$appt['appointment_id'];
          $isSelected = ($selectedApptId === $apptId);
          $statusName = (string)$appt['status_name'];
          $tailSide = ((int)$appt['day_index'] >= 4) ? 'tail-right' : 'tail-left';
        ?>
        <aside class="grooming-speech-bubble <?= $tailSide ?> <?= $isSelected ? 'is-open' : '' ?>"
               id="grooming-speech-bubble-<?= $apptId ?>"
               data-bubble-for="<?= $apptId ?>"
               role="dialog"
               aria-modal="false"
               aria-labelledby="grooming-bubble-title-<?= $apptId ?>"
               <?= $isSelected ? '' : 'hidden' ?>>
          <!-- Speech-Bubble Pointer / Tail -->
          <div class="grooming-speech-bubble-tail" aria-hidden="true"></div>

          <div class="grooming-speech-bubble-header">
            <div>
              <span class="eyebrow" style="margin-bottom: 0.15rem;">Veterinary Medical Appointment</span>
              <h3 id="grooming-bubble-title-<?= $apptId ?>" style="margin: 0; font-size: 1.1rem;">
                Appointment #<?= $apptId ?>
              </h3>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
              <span class="badge <?= e($appt['badge_class']) ?>"><?= e($appt['display_status'] ?? $statusName) ?></span>
              <button type="button"
                      class="grooming-bubble-close"
                      data-close-bubble="<?= $apptId ?>"
                      aria-label="Close appointment dialogue">&times;</button>
            </div>
          </div>

          <div class="grooming-speech-bubble-body">
            <dl class="grooming-bubble-dl">
              <div>
                <dt>Scheduled Date</dt>
                <dd><?= e($appt['formatted_date']) ?></dd>
              </div>
              <div>
                <dt>Time Coverage</dt>
                <dd class="tabular"><?= e($appt['time_coverage']) ?> (<?= (int)$appt['duration_minutes'] ?> mins)</dd>
              </div>
              <div>
                <dt>Customer</dt>
                <dd>
                  <strong><?= e($appt['customer_display_name']) ?></strong>
                  <?php if (!empty($appt['customer_id_display'])): ?>
                    <span class="caption muted">(#<?= (int)$appt['customer_id_display'] ?>)</span>
                  <?php else: ?>
                    <span class="badge" style="font-size: 0.65rem; background: #e0f2fe; color: #0369a1;">Guest</span>
                  <?php endif; ?>
                  <?php if (!empty($appt['customer_phone'])): ?>
                    <div class="caption muted">📞 <?= e($appt['customer_phone']) ?></div>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>Patient (Pet)</dt>
                <dd>🐾 <?= e($appt['pets_summary']) ?></dd>
              </div>
              <div>
                <dt>Medical Services</dt>
                <dd><?= e($appt['services_summary']) ?></dd>
              </div>
              <div>
                <dt>Attending Clinician</dt>
                <dd><strong>🩺 <?= e($appt['assigned_employee_display'] ?: 'Unassigned') ?></strong></dd>
              </div>
              <?php if (!empty($appt['notes'])): ?>
                <div>
                  <dt>Notes</dt>
                  <dd style="white-space: pre-line; font-size: 0.82rem;"><?= e($appt['notes']) ?></dd>
                </div>
              <?php endif; ?>
            </dl>

            <!-- Role-Appropriate Receptionist Scoped Actions -->
            <div class="grooming-bubble-actions">
              <?php if (in_array($statusName, ['Confirmed', 'In Progress', 'Completed'], true)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <span class="caption muted"><?= $statusName === 'Completed' ? 'Consultation completed' : 'Scheduled clinic consultation' ?></span>
                  <a href="/vetclinic/receptionist/billing" class="btn primary btn-sm">💳 View in Billing</a>
                </div>
              <?php elseif (in_array($statusName, ['Pending', 'Awaiting Customer Response'], true)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <span class="caption" style="color: var(--warn, #b45309);">Pending customer booking request</span>
                  <a href="/vetclinic/receptionist/appointments?tab=requests" class="btn ghost btn-sm">Review in Requests</a>
                </div>
              <?php else: ?>
                <div class="grooming-bubble-notice">
                  This appointment is in <strong><?= e($statusName) ?></strong> status and is archived as a permanent clinical record.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </aside>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- MODAL: + WALK-IN APPOINTMENT (4-STEP WIZARD)                              -->
<!-- ========================================================================= -->
<dialog class="modal modal-wide" id="walkin-modal" aria-labelledby="walkin-modal-title">
  <div class="modal-head">
    <div>
      <h3 id="walkin-modal-title" style="margin: 0; font-size: 1.25rem;">+ Walk-in Medical Appointment</h3>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">Register and confirm an in-person medical visit for today or future dates.</p>
    </div>
    <button type="button" class="btn ghost btn-sm" onclick="closeWalkinModal()">&times;</button>
  </div>

  <div class="modal-body" style="padding: 1.25rem;">
    <!-- Step Indicator (4 Steps) -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; position: relative;">
      <div class="step-indicator" id="step-ind-1" style="flex: 1; text-align: center; font-weight: 600; font-size: 0.85rem; color: var(--brand, #0e8a75); border-bottom: 3px solid var(--brand, #0e8a75); padding-bottom: 0.4rem;">
        1. Owner Info
      </div>
      <div class="step-indicator" id="step-ind-2" style="flex: 1; text-align: center; font-weight: 600; font-size: 0.85rem; color: var(--color-muted, #9ca3af); border-bottom: 3px solid #e5e7eb; padding-bottom: 0.4rem;">
        2. Pet Info
      </div>
      <div class="step-indicator" id="step-ind-3" style="flex: 1; text-align: center; font-weight: 600; font-size: 0.85rem; color: var(--color-muted, #9ca3af); border-bottom: 3px solid #e5e7eb; padding-bottom: 0.4rem;">
        3. Medical Services
      </div>
      <div class="step-indicator" id="step-ind-4" style="flex: 1; text-align: center; font-weight: 600; font-size: 0.85rem; color: var(--color-muted, #9ca3af); border-bottom: 3px solid #e5e7eb; padding-bottom: 0.4rem;">
        4. Date &amp; Time
      </div>
    </div>

    <form method="post" action="/vetclinic/receptionist/appointments/walk-in" id="walkin-form">
      <?= \App\Core\CSRF::field() ?>

      <!-- STEP 1: CUSTOMER / OWNER INFORMATION -->
      <div class="wizard-step" id="step-content-1">
        <h4 style="margin: 0 0 1rem 0; font-size: 1rem; color: var(--color-text, #111827);">Step 1: Customer / Owner Information</h4>

        <div style="margin-bottom: 1.25rem;">
          <span class="caption" style="display: block; margin-bottom: 0.5rem; font-weight: 600;">Customer Type:</span>
          <div style="display: flex; gap: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
              <input type="radio" name="customer_type" value="guest" checked onchange="handleCustomerTypeChange('guest')">
              <span>Guest / Walk-in Customer</span>
            </label>
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer;">
              <input type="radio" name="customer_type" value="registered" onchange="handleCustomerTypeChange('registered')">
              <span>Registered Customer</span>
            </label>
          </div>
        </div>

        <!-- Guest Fields -->
        <div id="guest-owner-section">
          <div class="field" style="margin-bottom: 1rem;">
            <label for="guest-name" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Owner Full Name <span style="color: #dc2626;">*</span></label>
            <input id="guest-name" type="text" name="guest_name" placeholder="e.g. Maria Clara" style="width: 100%;">
          </div>
          <div class="field" style="margin-bottom: 1rem;">
            <label for="guest-phone" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Contact Phone Number</label>
            <input id="guest-phone" type="tel" name="guest_phone" placeholder="e.g. 0917-123-4567" style="width: 100%;">
          </div>
          <p class="caption muted">Guest information is temporary visit info only. No permanent customer account will be created.</p>
        </div>

        <!-- Registered Customer Lookup -->
        <div id="registered-owner-section" style="display: none;">
          <div class="field" style="margin-bottom: 1rem; position: relative;">
            <label for="registered-search" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Search Registered Customer <span style="color: #dc2626;">*</span></label>
            <input id="registered-search" type="text" placeholder="Type customer name or phone to search..." autocomplete="off" style="width: 100%;" oninput="debounceCustomerSearch(this.value)">
            <div id="customer-suggestions" class="dropdown-menu" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; z-index: 50;"></div>
          </div>
          <div class="field" style="margin-bottom: 1rem;">
            <label for="selected-customer-id" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Customer ID (Read-only)</label>
            <input id="selected-customer-id" type="text" name="customer_id" readonly style="width: 100%; background: var(--color-bg-subtle, #f3f4f6); cursor: not-allowed;" placeholder="Selected after clicking a suggestion">
          </div>
          <div id="selected-customer-display" class="badge badge-success" style="display: none; font-size: 0.85rem; padding: 0.4rem 0.75rem; margin-bottom: 1rem;"></div>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
          <button type="button" class="btn primary btn-sm" onclick="goToStep(2)">
            Next: Pet Information &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 2: PET INFORMATION -->
      <div class="wizard-step" id="step-content-2" style="display: none;">
        <h4 style="margin: 0 0 1rem 0; font-size: 1rem; color: var(--color-text, #111827);">Step 2: Pet Information</h4>

        <div id="pets-container">
          <!-- Dynamically populated or rendered pet fields -->
        </div>

        <div style="margin-top: 1rem;">
          <button type="button" class="btn secondary btn-sm" id="add-another-pet-btn" onclick="addPetEntry()">
            + Add Another Pet
          </button>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border, #e5e7eb);">
          <button type="button" class="btn ghost btn-sm" onclick="goToStep(1)">
            &larr; Back to Owner Info
          </button>
          <button type="button" class="btn primary btn-sm" onclick="goToStep(3)">
            Next: Medical Services &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 3: MEDICAL SERVICES -->
      <div class="wizard-step" id="step-content-3" style="display: none;">
        <h4 style="margin: 0 0 1rem 0; font-size: 1rem; color: var(--color-text, #111827);">Step 3: Medical Services</h4>
        <p class="caption muted" style="margin-bottom: 1rem;">Select veterinary medical services for each patient. Multiple services per pet are allowed.</p>

        <div id="services-per-pet-container">
          <!-- Dynamically populated services selector for each pet from Step 2 -->
        </div>

        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb); margin-top: 1rem; display: flex; justify-content: space-between; align-items: center;">
          <div>
            <span class="caption muted">Total Estimated Duration:</span>
            <strong id="live-total-duration">30 mins</strong>
          </div>
          <div>
            <span class="caption muted">Subtotal Services:</span>
            <strong id="live-total-price" style="color: var(--brand, #0e8a75); font-size: 1.05rem;">₱0.00</strong>
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border, #e5e7eb);">
          <button type="button" class="btn ghost btn-sm" onclick="goToStep(2)">
            &larr; Back to Pet Info
          </button>
          <button type="button" class="btn primary btn-sm" onclick="goToStep(4)">
            Next: Date &amp; Time &rarr;
          </button>
        </div>
      </div>

      <!-- STEP 4: APPOINTMENT DATE & TIME -->
      <div class="wizard-step" id="step-content-4" style="display: none;">
        <h4 style="margin: 0 0 1rem 0; font-size: 1rem; color: var(--color-text, #111827);">Step 4: Appointment Schedule &amp; Confirmation</h4>

        <div class="grid grid-2" style="gap: 1rem; margin-bottom: 1rem;">
          <div class="field">
            <label for="walkin-date" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Appointment Date <span style="color: #dc2626;">*</span></label>
            <input id="walkin-date" type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required style="width: 100%;">
          </div>

          <div class="field">
            <label for="walkin-time" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Start Time <span style="color: #dc2626;">*</span></label>
            <input id="walkin-time" type="time" name="appointment_time" value="09:00" step="300" required style="width: 100%;">
          </div>
        </div>

        <div class="field" style="margin-bottom: 1rem;">
          <label for="walkin-notes" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Receptionist / Visit Notes</label>
          <textarea id="walkin-notes" name="walkin_notes" rows="2" placeholder="Primary concerns, patient symptoms, or special instructions..." style="width: 100%;"></textarea>
        </div>

        <!-- Summary Review Box -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;">
          <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Appointment Summary</span>
          <div style="margin-top: 0.5rem; font-size: 0.875rem;" id="walkin-summary-content">
            <!-- Populated via JS before final submit -->
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--color-border, #e5e7eb);">
          <button type="button" class="btn ghost btn-sm" onclick="goToStep(3)">
            &larr; Back to Medical Services
          </button>
          <button type="submit" class="btn primary btn-sm" id="submit-walkin-btn">
            ✓ Confirm Walk-in Appointment
          </button>
        </div>
      </div>
    </form>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: PROPOSE ALTERNATIVE TIME                                           -->
<!-- ========================================================================= -->
<dialog class="modal" id="propose-modal" aria-labelledby="propose-modal-title">
  <div class="modal-head">
    <h3 id="propose-modal-title" style="margin: 0; font-size: 1.15rem;">Propose Alternative Schedule</h3>
    <button type="button" class="btn ghost btn-sm" onclick="closeProposeModal()">&times;</button>
  </div>
  <div class="modal-body" style="padding: 1.25rem;">
    <form method="post" id="propose-form" action="">
      <?= \App\Core\CSRF::field() ?>
      <div class="field" style="margin-bottom: 1rem;">
        <label for="proposed-date" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Proposed Date</label>
        <input id="proposed-date" type="date" name="proposed_date" min="<?= date('Y-m-d') ?>" required style="width: 100%;">
      </div>
      <div class="field" style="margin-bottom: 1rem;">
        <label for="proposed-time" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Proposed Start Time</label>
        <input id="proposed-time" type="time" name="proposed_time" required style="width: 100%;">
      </div>
      <div class="field" style="margin-bottom: 1rem;">
        <label for="proposal-note" class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Note to Customer</label>
        <textarea id="proposal-note" name="proposal_note" rows="2" placeholder="e.g. Lead Vet is performing surgery in the morning. Proposed 2:00 PM slot is open..." style="width: 100%;"></textarea>
      </div>
      <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1rem;">
        <button type="button" class="btn ghost btn-sm" onclick="closeProposeModal()">Cancel</button>
        <button type="submit" class="btn primary btn-sm">Send Proposal</button>
      </div>
    </form>
  </div>
</dialog>

<script>
var currentCustomerType = 'guest';
var registeredPetsList = [];
var catalogServices = <?= json_encode($medicalServices, JSON_UNESCAPED_SLASHES) ?>;
var currentStep = 1;
var petCount = 0;

function openWalkinModal() {
  var dlg = document.getElementById('walkin-modal');
  if (dlg) {
    document.body.classList.add('has-modal-open');
    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    } else {
      dlg.setAttribute('open', '');
    }
    if (petCount === 0) {
      addPetEntry();
    }
    goToStep(1);
  }
}

function closeWalkinModal() {
  var dlg = document.getElementById('walkin-modal');
  if (dlg) {
    if (typeof dlg.close === 'function') {
      dlg.close();
    } else {
      dlg.removeAttribute('open');
    }
    document.body.classList.remove('has-modal-open');
  }
}

function openProposeModal(apptId, curDate, curTime) {
  var form = document.getElementById('propose-form');
  form.action = '/vetclinic/receptionist/appointments/requests/' + apptId + '/propose';
  document.getElementById('proposed-date').value = curDate;
  document.getElementById('proposed-time').value = curTime;

  var dlg = document.getElementById('propose-modal');
  if (dlg) {
    document.body.classList.add('has-modal-open');
    if (typeof dlg.showModal === 'function') {
      dlg.showModal();
    } else {
      dlg.setAttribute('open', '');
    }
  }
}

function closeProposeModal() {
  var dlg = document.getElementById('propose-modal');
  if (dlg) {
    if (typeof dlg.close === 'function') {
      dlg.close();
    } else {
      dlg.removeAttribute('open');
    }
    document.body.classList.remove('has-modal-open');
  }
}

function handleCustomerTypeChange(type) {
  currentCustomerType = type;
  var guestSec = document.getElementById('guest-owner-section');
  var regSec = document.getElementById('registered-owner-section');

  if (type === 'registered') {
    guestSec.style.display = 'none';
    regSec.style.display = 'block';
    document.getElementById('guest-name').value = '';
    document.getElementById('guest-phone').value = '';
  } else {
    guestSec.style.display = 'block';
    regSec.style.display = 'none';
    document.getElementById('registered-search').value = '';
    document.getElementById('selected-customer-id').value = '';
    document.getElementById('selected-customer-display').style.display = 'none';
    registeredPetsList = [];
  }
  rebuildPetEntries();
}

var searchDebounceTimer = null;
function debounceCustomerSearch(val) {
  clearTimeout(searchDebounceTimer);
  var term = val.trim();
  var box = document.getElementById('customer-suggestions');
  if (term.length < 2) {
    box.style.display = 'none';
    box.innerHTML = '';
    return;
  }
  searchDebounceTimer = setTimeout(function() {
    fetch('/vetclinic/receptionist/customers/search?q=' + encodeURIComponent(term))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!data.ok || !data.customers || data.customers.length === 0) {
          box.innerHTML = '<div style="padding: 0.5rem 0.75rem; color: #9ca3af; font-size: 0.85rem;">No matching registered customers found.</div>';
          box.style.display = 'block';
          return;
        }
        var html = '';
        data.customers.forEach(function(c) {
          html += '<div style="padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 0.85rem;" onmouseover="this.style.background=\'#f9fafb\'" onmouseout="this.style.background=\'#fff\'" onclick="selectRegisteredCustomer(' + c.id + ', \'' + escapeHtml(c.name) + '\', \'' + escapeHtml(c.label) + '\')">' +
            '<strong>' + escapeHtml(c.label) + '</strong>' +
            (c.phone ? ' <span class="caption muted">(' + escapeHtml(c.phone) + ')</span>' : '') +
            '</div>';
        });
        box.innerHTML = html;
        box.style.display = 'block';
      });
  }, 250);
}

function selectRegisteredCustomer(id, name, label) {
  document.getElementById('selected-customer-id').value = id;
  document.getElementById('registered-search').value = label;
  document.getElementById('customer-suggestions').style.display = 'none';

  var badge = document.getElementById('selected-customer-display');
  badge.textContent = 'Selected: ' + label;
  badge.style.display = 'inline-block';

  // Load customer's registered pets
  fetch('/vetclinic/receptionist/customers/' + id + '/pets')
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.ok && data.pets) {
        registeredPetsList = data.pets;
      } else {
        registeredPetsList = [];
      }
      rebuildPetEntries();
    });
}

function addPetEntry() {
  petCount++;
  var container = document.getElementById('pets-container');
  var div = document.createElement('div');
  div.id = 'pet-card-' + petCount;
  div.className = 'card';
  div.style.cssText = 'padding: 1rem; border: 1px solid var(--color-border, #e5e7eb); background: var(--color-bg-subtle, #f9fafb); margin-bottom: 1rem; border-radius: 6px;';

  var pIdx = petCount;
  var html = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">' +
    '<strong style="font-size: 0.95rem; color: var(--color-text, #111827);">Patient / Pet #' + pIdx + '</strong>' +
    (pIdx > 1 ? '<button type="button" class="btn ghost btn-sm" style="color: #dc2626; padding: 0 0.4rem;" onclick="removePetEntry(' + pIdx + ')">&times; Remove</button>' : '') +
    '</div>';

  if (currentCustomerType === 'registered') {
    html += '<div class="field" style="margin-bottom: 0.75rem;">' +
      '<label class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Choose Registered Pet <span style="color: #dc2626;">*</span></label>' +
      '<select name="pets[' + pIdx + '][pet_id]" class="registered-pet-select" style="width: 100%;" onchange="handlePetSelectChange(' + pIdx + ', this.value)">' +
      '<option value="">-- Select a registered pet --</option>';
    registeredPetsList.forEach(function(pt) {
      var dName = pt.pet_name + ' (' + (pt.breed || pt.species) + ')';
      html += '<option value="' + pt.pet_id + '">' + escapeHtml(dName) + '</option>';
    });
    html += '</select></div>';
  } else {
    html += '<div class="grid grid-3" style="gap: 0.75rem; margin-bottom: 0.75rem;">' +
      '<div class="field">' +
      '<label class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Pet Name <span style="color: #dc2626;">*</span></label>' +
      '<input type="text" name="pets[' + pIdx + '][name]" placeholder="e.g. Bruno" required style="width: 100%;">' +
      '</div>' +
      '<div class="field">' +
      '<label class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Species <span style="color: #dc2626;">*</span></label>' +
      '<select name="pets[' + pIdx + '][species]" style="width: 100%;">' +
      '<option value="Dog">Dog</option>' +
      '<option value="Cat">Cat</option>' +
      '<option value="Bird">Bird</option>' +
      '<option value="Other">Other</option>' +
      '</select>' +
      '</div>' +
      '<div class="field">' +
      '<label class="caption" style="display: block; margin-bottom: 0.25rem; font-weight: 600;">Breed / Mix</label>' +
      '<input type="text" name="pets[' + pIdx + '][breed]" placeholder="e.g. Golden Retriever" style="width: 100%;">' +
      '</div>' +
      '</div>';
  }

  div.innerHTML = html;
  container.appendChild(div);
}

function removePetEntry(pIdx) {
  var card = document.getElementById('pet-card-' + pIdx);
  if (card) {
    card.remove();
  }
}

function rebuildPetEntries() {
  var container = document.getElementById('pets-container');
  container.innerHTML = '';
  petCount = 0;
  addPetEntry();
}

function handlePetSelectChange(pIdx, petId) {
  // Handled during service step build
}

function goToStep(step) {
  // Validate Step 1
  if (currentStep === 1 && step > 1) {
    if (currentCustomerType === 'guest') {
      var gName = document.getElementById('guest-name').value.trim();
      if (!gName) {
        alert('Please enter guest owner name.');
        return;
      }
    } else {
      var rId = document.getElementById('selected-customer-id').value.trim();
      if (!rId) {
        alert('Please search and select a registered customer.');
        return;
      }
    }
  }

  // Validate Step 2
  if (currentStep === 2 && step > 2) {
    if (currentCustomerType === 'guest') {
      var inputs = document.querySelectorAll('#pets-container input[name*="[name]"]');
      var valid = false;
      inputs.forEach(function(inp) { if (inp.value.trim()) valid = true; });
      if (!valid) {
        alert('Please provide pet name.');
        return;
      }
    } else {
      var selects = document.querySelectorAll('.registered-pet-select');
      var selValid = false;
      selects.forEach(function(s) { if (s.value) selValid = true; });
      if (!selValid) {
        alert('Please select a registered pet.');
        return;
      }
    }
    buildServicesStep();
  }

  // Validate Step 3
  if (currentStep === 3 && step > 3) {
    var checkedServices = document.querySelectorAll('#services-per-pet-container input[type="checkbox"]:checked');
    if (checkedServices.length === 0) {
      alert('Please select at least one medical service.');
      return;
    }
    buildSummaryStep();
  }

  // Show target step
  for (var i = 1; i <= 4; i++) {
    var content = document.getElementById('step-content-' + i);
    var ind = document.getElementById('step-ind-' + i);
    if (content) {
      content.style.display = (i === step) ? 'block' : 'none';
    }
    if (ind) {
      if (i === step) {
        ind.style.color = 'var(--brand, #0e8a75)';
        ind.style.borderBottomColor = 'var(--brand, #0e8a75)';
      } else if (i < step) {
        ind.style.color = 'var(--color-text, #111827)';
        ind.style.borderBottomColor = '#10b981';
      } else {
        ind.style.color = 'var(--color-muted, #9ca3af)';
        ind.style.borderBottomColor = '#e5e7eb';
      }
    }
  }
  currentStep = step;
}

function buildServicesStep() {
  var container = document.getElementById('services-per-pet-container');
  container.innerHTML = '';

  var petCards = document.querySelectorAll('#pets-container > div');
  petCards.forEach(function(card, idx) {
    var pIdx = idx + 1;
    var petLabel = 'Pet #' + pIdx;
    if (currentCustomerType === 'guest') {
      var nInp = card.querySelector('input[name*="[name]"]');
      if (nInp && nInp.value.trim()) {
        petLabel = nInp.value.trim();
      }
    } else {
      var sel = card.querySelector('select');
      if (sel && sel.selectedIndex > 0) {
        petLabel = sel.options[sel.selectedIndex].text;
      }
    }

    var div = document.createElement('div');
    div.style.cssText = 'background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 6px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1rem;';

    var html = '<div style="font-weight: 700; font-size: 0.95rem; color: var(--color-text, #111827); margin-bottom: 0.5rem;">🐾 Medical Services for ' + escapeHtml(petLabel) + '</div>' +
      '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0.5rem;">';

    catalogServices.forEach(function(s) {
      html += '<label style="display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem; background: #fff; border: 1px solid var(--color-border, #e5e7eb); border-radius: 6px; cursor: pointer;">' +
        '<div style="display: flex; align-items: center; gap: 0.5rem;">' +
        '<input type="checkbox" name="pets[' + pIdx + '][services][]" value="' + s.service_id + '" data-price="' + s.base_price + '" data-duration="' + s.estimated_duration_minutes + '" onchange="recalcServicesLive()">' +
        '<span style="font-size: 0.85rem; font-weight: 500;">' + escapeHtml(s.service_name) + '</span>' +
        '</div>' +
        '<div style="text-align: right; font-size: 0.85rem; font-weight: 600; color: var(--brand, #0e8a75);">' +
        '₱' + parseFloat(s.base_price).toFixed(2) +
        '<div style="font-size: 0.7rem; color: #6b7280; font-weight: normal;">~' + s.estimated_duration_minutes + 'm</div>' +
        '</div>' +
        '</label>';
    });

    html += '</div>';
    div.innerHTML = html;
    container.appendChild(div);
  });
  recalcServicesLive();
}

function recalcServicesLive() {
  var checkboxes = document.querySelectorAll('#services-per-pet-container input[type="checkbox"]:checked');
  var totalDur = 0;
  var totalPrice = 0.0;
  var seenDurations = {};

  checkboxes.forEach(function(cb) {
    var p = parseFloat(cb.getAttribute('data-price') || 0);
    var d = parseInt(cb.getAttribute('data-duration') || 15, 10);
    totalPrice += p;
    totalDur += d;
  });

  if (totalDur <= 0) totalDur = 30;

  document.getElementById('live-total-duration').textContent = totalDur + ' mins';
  document.getElementById('live-total-price').textContent = '₱' + totalPrice.toFixed(2);
}

function buildSummaryStep() {
  var box = document.getElementById('walkin-summary-content');
  var custName = (currentCustomerType === 'guest')
    ? document.getElementById('guest-name').value.trim() + ' (Guest Walk-in)'
    : document.getElementById('registered-search').value.trim();

  var checkboxes = document.querySelectorAll('#services-per-pet-container input[type="checkbox"]:checked');
  var svcNames = [];
  var totalPrice = 0.0;
  var totalDur = 0;

  checkboxes.forEach(function(cb) {
    var label = cb.parentElement.textContent.trim();
    svcNames.push(label);
    totalPrice += parseFloat(cb.getAttribute('data-price') || 0);
    totalDur += parseInt(cb.getAttribute('data-duration') || 15, 10);
  });

  box.innerHTML = '<div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;"><span>Customer:</span> <strong>' + escapeHtml(custName) + '</strong></div>' +
    '<div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;"><span>Selected Services:</span> <strong>' + svcNames.length + ' service(s)</strong></div>' +
    '<div style="display: flex; justify-content: space-between; margin-bottom: 0.35rem;"><span>Estimated Duration:</span> <strong>~' + totalDur + ' minutes</strong></div>' +
    '<div style="display: flex; justify-content: space-between; font-size: 1rem; color: var(--brand, #0e8a75); padding-top: 0.35rem; border-top: 1px dashed #e5e7eb;"><span>Total Consultation Fee:</span> <strong>₱' + totalPrice.toFixed(2) + '</strong></div>';
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('#registered-search') && !e.target.closest('#customer-suggestions')) {
    var cs = document.getElementById('customer-suggestions');
    if (cs) cs.style.display = 'none';
  }
});

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>
