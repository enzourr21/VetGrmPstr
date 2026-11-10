<?php
$title = $title ?? 'Appointments · Grooming Services Receptionist';
$activeTab = $activeTab ?? 'requests';
$reqList = $requests ?? [];
$svcs = $groomingServices ?? [];
$staffList = $groomers ?? [];
$wkOffset = $weekOffset ?? 0;
$weekDays = $weekDays ?? [];
$rangeHeading = $rangeHeading ?? '7-Day Schedule';
$monthPickerValue = $monthPickerValue ?? date('Y-m');
$isCurrentWeek = $isCurrentWeek ?? true;
$prevWeekStart = $prevWeekStart ?? date('Y-m-d', strtotime('-7 days'));
$nextWeekStart = $nextWeekStart ?? date('Y-m-d', strtotime('+7 days'));
$todayWeekStart = $todayWeekStart ?? date('Y-m-d');
$gridStartHour = $gridStartHour ?? 8;
$gridEndHour = $gridEndHour ?? 20;
$gridHeightPx = $gridHeightPx ?? 720;
$appointmentsByDate = $appointmentsByDate ?? [];
$allVisibleAppointments = $allVisibleAppointments ?? [];
$selectedApptId = $selectedApptId ?? null;
?>

<div class="page-head" style="margin-bottom: 1.5rem;">
  <div>
    <p class="eyebrow">Grooming Services Workspace</p>
    <h1>Appointments</h1>
    <p class="muted">Review incoming customer appointment requests, manage the 7-day studio schedule, and register walk-in visits.</p>
  </div>
</div>

<!-- Navigation Tabs: Exactly 2 Tabs -->
<div class="tabs-nav" style="display: flex; gap: 0.5rem; border-bottom: 2px solid var(--color-border, #e5e7eb); margin-bottom: 1.5rem;">
  <a href="/grooming/receptionist/appointments?tab=requests"
     class="tab-link <?= $activeTab === 'requests' ? 'is-active' : '' ?>"
     style="padding: 0.65rem 1.25rem; font-weight: 600; text-decoration: none; border-bottom: 2px solid <?= $activeTab === 'requests' ? 'var(--brand, #0e8a75)' : 'transparent' ?>; margin-bottom: -2px; color: <?= $activeTab === 'requests' ? 'var(--brand, #0e8a75)' : 'var(--color-muted, #6b7280)' ?>;">
    Appointment Requests
    <?php if (count($reqList) > 0): ?>
      <span class="badge badge-warning" style="margin-left: 0.4rem; font-size: 0.75rem; padding: 0.15rem 0.45rem;">
        <?= count($reqList) ?>
      </span>
    <?php endif; ?>
  </a>
  <a href="/grooming/receptionist/appointments?tab=calendar"
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
          Review online bookings from registered customers. Accept the schedule or propose an alternative start time.
        </p>
      </div>
    </div>

    <?php if (empty($reqList)): ?>
      <div class="empty" style="padding: 3rem 1rem; text-align: center;">
        <div style="font-size: 2.25rem; margin-bottom: 0.5rem;" aria-hidden="true">📬</div>
        <h3 style="margin: 0 0 0.35rem 0; font-size: 1.1rem; color: var(--color-text, #111827);">No Pending Requests</h3>
        <p class="muted" style="margin: 0; font-size: 0.9rem;">All customer booking requests have been processed. Switch to the Calendar tab to review current bookings.</p>
        <div style="margin-top: 1rem;">
          <a href="/grooming/receptionist/appointments?tab=calendar" class="btn ghost btn-sm">Open Appointments Calendar</a>
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
                    <?= e($req['status_name']) ?>
                  </span>
                </div>
                <div style="margin-top: 0.35rem; font-size: 0.95rem; font-weight: 600; color: var(--color-text, #374151);">
                  Customer: <?= e($req['customer_display_name']) ?>
                  <?php if (!empty($req['customer_id_display'])): ?>
                    <span class="caption muted">(#<?= (int)$req['customer_id_display'] ?>)</span>
                  <?php endif; ?>
                </div>
                <div class="caption muted" style="margin-top: 0.15rem;">
                  <?= !empty($req['customer_phone']) ? '📞 ' . e($req['customer_phone']) : '' ?>
                  <?= !empty($req['cust_email']) ? ' • ✉️ ' . e($req['cust_email']) : '' ?>
                </div>
              </div>

              <!-- Requested Date & Time Badge -->
              <div style="text-align: right;">
                <div style="font-weight: 700; font-size: 1.05rem; color: var(--color-text, #111827);">
                  <?= date('M j, Y', strtotime((string)$req['preferred_date'])) ?>
                </div>
                <div style="font-weight: 600; color: var(--brand, #0e8a75); font-size: 0.95rem; margin-top: 0.15rem;">
                  🕒 <?= !empty($req['preferred_time']) ? date('g:i A', strtotime((string)$req['preferred_time'])) : 'Preferred Time Unspecified' ?>
                </div>
                <div class="caption muted" style="margin-top: 0.15rem;">
                  Est. Duration: ~<?= (int)($req['duration_minutes'] ?? 60) ?> mins
                </div>
              </div>
            </div>

            <!-- Pets & Services Detail -->
            <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px solid var(--color-border, #e5e7eb); display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
              <div>
                <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Registered Pets</span>
                <div style="margin-top: 0.25rem; font-weight: 500; color: var(--color-text, #1f2937);">
                  🐾 <?= e($req['pets_summary'] ?? 'Pet recorded') ?>
                </div>
              </div>

              <div>
                <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Selected Services</span>
                <div style="margin-top: 0.25rem; color: var(--color-text, #374151); font-size: 0.9rem;">
                  <?= e($req['services_summary'] ?? 'Standard Grooming') ?>
                </div>
              </div>

              <?php if (!empty($req['notes'])): ?>
                <div>
                  <span class="caption muted" style="font-weight: 600; text-transform: uppercase;">Customer Notes</span>
                  <div style="margin-top: 0.25rem; font-size: 0.85rem; color: var(--color-text, #4b5563); font-style: italic;">
                    <?= nl2br(e($req['notes'])) ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <!-- Action Controls: Accept / Propose Alternative / Decline -->
            <div style="margin-top: 1.25rem; display: flex; justify-content: flex-end; gap: 0.5rem; flex-wrap: wrap;">
              <!-- Accept Schedule Form -->
              <form method="post" action="/grooming/receptionist/appointments/requests/<?= (int)$req['appointment_id'] ?>/accept" onsubmit="return confirm('Accept and confirm Grooming request #<?= (int)$req['appointment_id'] ?> for the requested schedule?');">
                <?= \App\Core\CSRF::field() ?>
                <button type="submit" class="btn primary btn-sm">
                  ✓ Accept Schedule
                </button>
              </form>

              <!-- Propose Alternative Button (Opens Modal) -->
              <button type="button" class="btn ghost btn-sm" onclick="openProposeModal(<?= (int)$req['appointment_id'] ?>, '<?= e($req['preferred_date']) ?>', '<?= e($req['preferred_time'] ? substr($req['preferred_time'], 0, 5) : '09:00') ?>')">
                ⏱ Propose Alternative Time
              </button>

              <!-- Decline Form -->
              <form method="post" action="/grooming/receptionist/appointments/requests/<?= (int)$req['appointment_id'] ?>/reject" onsubmit="return confirm('Decline Grooming appointment request #<?= (int)$req['appointment_id'] ?>?');">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="reason" value="Scheduling conflict or facility capacity">
                <button type="submit" class="btn ghost btn-sm" style="color: #b91c1c;">
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
  <!-- TAB 2: APPOINTMENTS CALENDAR (Matching Grooming BM Weekly Calendar)      -->
  <!-- ========================================================================= -->

  <!-- Calendar Navigation Toolbar Matching Grooming BM Structure -->
  <div class="card grooming-cal-toolbar" style="padding: 0.75rem 1.25rem; margin-bottom: 0.85rem;">
    <div class="grooming-cal-nav-row">
      <!-- Far Left: Previous 7-Day Range (arrow only: ‹) -->
      <a href="/grooming/receptionist/appointments?tab=calendar&start=<?= e($prevWeekStart) ?>"
         class="btn secondary btn-sm grooming-cal-nav-btn"
         id="grooming-cal-prev"
         title="Previous 7 days"
         aria-label="Previous 7 days">&lsaquo;</a>

      <!-- Center: Today button, Active 7-Day Range Label, and Month Picker -->
      <div class="grooming-cal-nav-center">
        <a href="/grooming/receptionist/appointments?tab=calendar&start=<?= e($todayWeekStart) ?>"
           class="btn <?= $isCurrentWeek ? 'secondary' : 'ghost' ?> btn-sm grooming-cal-today-btn"
           id="grooming-cal-today">
          Today
        </a>

        <strong class="grooming-cal-range-label" id="grooming-cal-range-label">
          <?= e($rangeHeading) ?>
        </strong>
        <span class="badge secondary" style="font-size: 0.76rem; font-weight: 600;" id="grooming-rec-overlap-badge" title="Maximum allowed appointment overlap configured for <?= e($unitName) ?>">
          Max Overlap: <?= (int)($maxOverlapMinutes ?? 15) ?>m
        </span>

        <form method="get" action="/grooming/receptionist/appointments" class="grooming-cal-month-form" id="grooming-cal-month-form">
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

      <!-- Far Right: Next 7-Day Range & + Walk-in Appointment Button -->
      <div style="display: flex; align-items: center; gap: 0.75rem;">
        <a href="/grooming/receptionist/appointments?tab=calendar&start=<?= e($nextWeekStart) ?>"
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

  <!-- Google Calendar-inspired 7-Day Weekly Calendar Matching Grooming BM -->
  <div class="card grooming-weekly-calendar-card" id="grooming-weekly-calendar-card">
    <!-- 7-Day Column Header Row -->
    <div class="grooming-cal-header-grid">
      <div class="grooming-cal-time-gutter-head">GMT+8</div>
      <?php foreach ($weekDays as $dayInfo): ?>
        <div class="grooming-cal-day-head <?= $dayInfo['is_today'] ? 'is-today' : '' ?>" data-date="<?= e($dayInfo['date']) ?>">
          <span class="grooming-cal-day-name"><?= e($dayInfo['day_short']) ?></span>
          <strong class="grooming-cal-day-num <?= $dayInfo['is_today'] ? 'is-today-pill' : '' ?>">
            <?= e($dayInfo['day_label']) ?>
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

      <!-- Speech-Bubble Dialogue Boxes (One per appointment in the visible 7-day range) -->
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
              <span class="eyebrow" style="margin-bottom: 0.15rem;">Grooming Appointment</span>
              <h3 id="grooming-bubble-title-<?= $apptId ?>" style="margin: 0; font-size: 1.1rem;">
                Appointment #<?= $apptId ?>
              </h3>
            </div>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
              <span class="badge <?= e($appt['badge_class']) ?>"><?= e($statusName) ?></span>
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
                  <strong><?= e($appt['customer_name']) ?></strong>
                  <?php if (!empty($appt['customer_phone'])): ?>
                    <span class="caption"> &middot; <?= e($appt['customer_phone']) ?></span>
                  <?php endif; ?>
                </dd>
              </div>
              <div>
                <dt>Pet &amp; Breed</dt>
                <dd><?= e($appt['pet_summary']) ?></dd>
              </div>
              <div>
                <dt>Grooming Services</dt>
                <dd><?= e($appt['services_summary']) ?></dd>
              </div>
              <div>
                <dt>Assigned Employee</dt>
                <dd><strong><?= e($appt['assigned_employee'] ?: 'Unassigned') ?></strong></dd>
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
              <?php if (in_array($statusName, ['Confirmed', 'In Progress'], true)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <span class="caption muted">Scheduled salon appointment</span>
                  <a href="/grooming/receptionist/billing" class="btn primary btn-sm">💳 View in Billing</a>
                </div>
              <?php elseif (in_array($statusName, ['Pending', 'Awaiting Customer Response'], true)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <span class="caption" style="color: var(--warn, #b45309);">Pending customer booking request</span>
                  <a href="/grooming/receptionist/appointments?tab=requests" class="btn ghost btn-sm">Review in Requests</a>
                </div>
              <?php elseif ($statusName === 'Completed'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                  <span class="caption" style="color: var(--ok, #047857);">Session completed</span>
                  <a href="/grooming/receptionist/records" class="btn ghost btn-sm">View in Records</a>
                </div>
              <?php else: ?>
                <div class="grooming-bubble-notice">
                  This appointment is in <strong><?= e($statusName) ?></strong> status.
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
<!-- MODAL: + WALK-IN APPOINTMENT (3-PART WIZARD)                              -->
<!-- ========================================================================= -->
<dialog class="modal modal-wide" id="walkin-modal" aria-labelledby="walkin-modal-title">
  <div class="modal-head">
    <div>
      <h3 id="walkin-modal-title" style="margin: 0; font-size: 1.25rem;">+ Walk-in Appointment</h3>
      <p class="caption muted" style="margin: 0.2rem 0 0 0;">Book a direct salon walk-in visit for guest or registered clients.</p>
    </div>
    <button type="button" class="btn ghost btn-sm" data-modal-close onclick="closeWalkinModal()" aria-label="Close dialog">&times;</button>
  </div>

  <div class="modal-body" style="padding: 1.5rem;">
    <!-- Step Progress Indicator -->
    <div style="display: flex; justify-content: space-between; margin-bottom: 1.5rem; position: relative;">
      <div style="position: absolute; top: 12px; left: 15%; right: 15%; height: 2px; background: var(--color-border, #e5e7eb); z-index: 1;"></div>
      
      <div id="step-pill-1" style="display: flex; flex-direction: column; align-items: center; z-index: 2; position: relative;">
        <span style="width: 26px; height: 26px; border-radius: 50%; background: var(--brand, #0e8a75); color: #fff; font-size: 0.8rem; font-weight: 700; display: flex; align-items: center; justify-content: center;">1</span>
        <span class="caption" style="font-weight: 600; margin-top: 0.25rem; color: var(--color-text, #111827);">Customer</span>
      </div>

      <div id="step-pill-2" style="display: flex; flex-direction: column; align-items: center; z-index: 2; position: relative;">
        <span style="width: 26px; height: 26px; border-radius: 50%; background: #d1d5db; color: #fff; font-size: 0.8rem; font-weight: 700; display: flex; align-items: center; justify-content: center;">2</span>
        <span class="caption muted" style="font-weight: 600; margin-top: 0.25rem;">Pets &amp; Services</span>
      </div>

      <div id="step-pill-3" style="display: flex; flex-direction: column; align-items: center; z-index: 2; position: relative;">
        <span style="width: 26px; height: 26px; border-radius: 50%; background: #d1d5db; color: #fff; font-size: 0.8rem; font-weight: 700; display: flex; align-items: center; justify-content: center;">3</span>
        <span class="caption muted" style="font-weight: 600; margin-top: 0.25rem;">Date &amp; Time</span>
      </div>
    </div>

    <form method="post" action="/grooming/receptionist/appointments/walk-in" id="walkin-form">
      <?= \App\Core\CSRF::field() ?>

      <!-- ------------------------------------------------------------------- -->
      <!-- PART 1: CUSTOMER INFORMATION                                        -->
      <!-- ------------------------------------------------------------------- -->
      <div id="walkin-part-1">
        <div style="margin-bottom: 1rem;">
          <span class="caption" style="font-weight: 600; display: block; margin-bottom: 0.4rem;">Select Customer Type</span>
          <div style="display: flex; gap: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-size: 0.95rem;">
              <input type="radio" name="customer_type" value="guest" checked onchange="handleCustomerTypeChange('guest')">
              <span><strong>Guest Customer</strong> (Walk-in)</span>
            </label>
            <label style="display: flex; align-items: center; gap: 0.4rem; cursor: pointer; font-size: 0.95rem;">
              <input type="radio" name="customer_type" value="registered" onchange="handleCustomerTypeChange('registered')">
              <span><strong>Registered Customer</strong></span>
            </label>
          </div>
        </div>

        <!-- Guest Inputs -->
        <div id="guest-inputs-wrap">
          <div class="field" style="margin-bottom: 0.75rem;">
            <label for="guest-name-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Guest Customer Name *</label>
            <input type="text" id="guest-name-input" name="guest_name" placeholder="Enter guest customer full name..." style="width: 100%;" required>
          </div>
          <div class="field" style="margin-bottom: 0.75rem;">
            <label for="guest-phone-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Contact Number (Optional)</label>
            <input type="tel" id="guest-phone-input" name="guest_phone" placeholder="e.g. 0917-123-4567" style="width: 100%;">
          </div>
          <div class="field" style="margin-bottom: 0.75rem;">
            <label class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Customer ID</label>
            <input type="text" value="NULL" readonly disabled style="width: 100%; background: #f3f4f6; color: #9ca3af; cursor: not-allowed;">
            <p class="caption muted" style="margin: 0.25rem 0 0 0;">Customer ID remains NULL for guest walk-ins. No permanent profile is created.</p>
          </div>
        </div>

        <!-- Registered Inputs -->
        <div id="registered-inputs-wrap" style="display: none;">
          <div class="field" style="margin-bottom: 0.75rem; position: relative;">
            <label for="registered-search-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Search Registered Customer *</label>
            <input type="search" id="registered-search-input" placeholder="Type name, email, or phone to search..." style="width: 100%;" autocomplete="off" oninput="onRegisteredCustomerSearch(this.value)">
            <div id="registered-suggestions" style="position: absolute; left: 0; right: 0; top: 100%; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-height: 180px; overflow-y: auto; z-index: 10; display: none;"></div>
          </div>
          <div class="field" style="margin-bottom: 0.75rem;">
            <label class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Customer ID (Read-only)</label>
            <input type="text" id="registered-customer-id-display" readonly style="width: 100%; background: #f3f4f6; font-weight: 600;" placeholder="Populated only when a search suggestion is clicked">
            <input type="hidden" name="customer_id" id="registered-customer-id-hidden">
          </div>
        </div>

        <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
          <button type="button" class="btn primary btn-sm" onclick="goToStep(2)">Next: Pets &amp; Services &rarr;</button>
        </div>
      </div>

      <!-- ------------------------------------------------------------------- -->
      <!-- PART 2: PETS AND SERVICES                                           -->
      <!-- ------------------------------------------------------------------- -->
      <div id="walkin-part-2" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.05rem;">Pets &amp; Selected Services</h3>
          <button type="button" class="btn ghost btn-sm" onclick="addPetEntry()">+ Add Another Pet</button>
        </div>

        <!-- Container for Dynamic Pet Cards -->
        <div id="pets-container" style="display: flex; flex-direction: column; gap: 1rem; max-height: 380px; overflow-y: auto; padding-right: 0.25rem;">
          <!-- Pet entries injected dynamically -->
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1.5rem;">
          <button type="button" class="btn ghost btn-sm" onclick="goToStep(1)">&larr; Back to Customer</button>
          <button type="button" class="btn primary btn-sm" onclick="goToStep(3)">Next: Date &amp; Time &rarr;</button>
        </div>
      </div>

      <!-- ------------------------------------------------------------------- -->
      <!-- PART 3: APPOINTMENT DATE & CONFIRMATION                             -->
      <!-- ------------------------------------------------------------------- -->
      <div id="walkin-part-3" style="display: none;">
        <h3 style="margin: 0 0 0.75rem 0; font-size: 1.05rem;">Schedule &amp; Availability Verification</h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
          <div class="field" style="margin: 0;">
            <label for="appt-date-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Appointment Date *</label>
            <input type="date" id="appt-date-input" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required style="width: 100%;">
          </div>

          <div class="field" style="margin: 0;">
            <label for="appt-time-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Start Time *</label>
            <input type="time" id="appt-time-input" name="appointment_time" value="<?= date('H:i') ?>" required style="width: 100%;">
          </div>
        </div>

        <div class="field" style="margin-bottom: 1rem;">
          <label for="walkin-notes-input" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Receptionist Notes / Special Instructions</label>
          <textarea id="walkin-notes-input" name="walkin_notes" rows="2" placeholder="e.g. Sensitive skin, careful with ears, requested quiet handling..." style="width: 100%;"></textarea>
        </div>

        <!-- Automatic Availability & Qualification Preview -->
        <div style="background: var(--color-bg-subtle, #f9fafb); padding: 1rem; border-radius: 8px; border: 1px solid var(--color-border, #e5e7eb); margin-bottom: 1.25rem;">
          <div style="font-weight: 600; font-size: 0.9rem; color: var(--color-text, #111827); margin-bottom: 0.25rem;">
            Automatic Qualification &amp; Duration Check
          </div>
          <div class="caption muted" id="walkin-summary-duration">
            Estimated Duration: ~60 minutes
          </div>
          <div class="caption" style="margin-top: 0.35rem; color: #047857; font-weight: 500;">
            ✓ Studio Operating Hours: Mon–Fri 8:00 AM–7:00 PM, Sat 9:00 AM–6:00 PM, Sun 9:00 AM–5:00 PM
          </div>
          <div class="caption" style="margin-top: 0.2rem; color: #047857; font-weight: 500;">
            ✓ Qualified Grooming Staff: Master Stylist &amp; Stylists automatically assigned upon confirmation.
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; margin-top: 1.5rem;">
          <button type="button" class="btn ghost btn-sm" onclick="goToStep(2)">&larr; Back to Services</button>
          <button type="submit" class="btn primary btn-sm" id="confirm-walkin-submit">✓ Confirm &amp; Save Walk-in</button>
        </div>
      </div>
    </form>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: PROPOSE ALTERNATIVE TIME                                           -->
<!-- ========================================================================= -->
<dialog class="modal" id="propose-modal" aria-labelledby="propose-modal-title" style="max-width: 480px;">
  <div class="modal-head">
    <h3 id="propose-modal-title" style="margin: 0; font-size: 1.15rem;">Propose Alternative Start Time</h3>
    <button type="button" class="btn ghost btn-sm" data-modal-close onclick="closeProposeModal()" aria-label="Close dialog">&times;</button>
  </div>

  <div class="modal-body" style="padding: 1.5rem;">
    <form method="post" id="propose-form" action="">
      <?= \App\Core\CSRF::field() ?>
      <p class="caption muted" style="margin: 0 0 1rem 0;">
        The appointment booking will remain pending with status <strong>Awaiting Customer Response</strong> until the customer replies.
      </p>

      <div class="field" style="margin-bottom: 0.75rem;">
        <label for="proposed-date" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Proposed Date *</label>
        <input type="date" id="proposed-date" name="proposed_date" min="<?= date('Y-m-d') ?>" required style="width: 100%;">
      </div>

      <div class="field" style="margin-bottom: 0.75rem;">
        <label for="proposed-time" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Proposed Start Time *</label>
        <input type="time" id="proposed-time" name="proposed_time" required style="width: 100%;">
      </div>

      <div class="field" style="margin-bottom: 1rem;">
        <label for="proposal-note" class="caption" style="display: block; font-weight: 600; margin-bottom: 0.25rem;">Note to Customer (Optional)</label>
        <textarea id="proposal-note" name="proposal_note" rows="2" placeholder="e.g. Studio is fully booked at 10 AM, but we have an opening at 1:30 PM..." style="width: 100%;"></textarea>
      </div>

      <div style="display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button type="button" class="btn ghost btn-sm" onclick="closeProposeModal()">Cancel</button>
        <button type="submit" class="btn primary btn-sm">Send Proposal</button>
      </div>
    </form>
  </div>
</dialog>

<script>
// Catalog of active Grooming services available in JavaScript
var GROOMING_SERVICES = <?= json_encode($svcs, JSON_UNESCAPED_SLASHES) ?>;
var currentCustomerType = 'guest';
var registeredPetsList = [];
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
  form.action = '/grooming/receptionist/appointments/requests/' + apptId + '/propose';
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
  if (type === 'guest') {
    document.getElementById('guest-inputs-wrap').style.display = 'block';
    document.getElementById('registered-inputs-wrap').style.display = 'none';
    document.getElementById('guest-name-input').setAttribute('required', '');
    document.getElementById('registered-customer-id-hidden').value = '';
    document.getElementById('registered-customer-id-display').value = '';
    document.getElementById('registered-search-input').value = '';
  } else {
    document.getElementById('guest-inputs-wrap').style.display = 'none';
    document.getElementById('registered-inputs-wrap').style.display = 'block';
    document.getElementById('guest-name-input').removeAttribute('required');
  }
  // Clear pet entries when customer type changes
  document.getElementById('pets-container').innerHTML = '';
  petCount = 0;
  addPetEntry();
}

function onRegisteredCustomerSearch(query) {
  var box = document.getElementById('registered-suggestions');
  var q = query.trim();
  // Typing free text without selecting a result must not populate the Customer ID
  document.getElementById('registered-customer-id-hidden').value = '';
  document.getElementById('registered-customer-id-display').value = '';

  if (q.length < 1) {
    box.style.display = 'none';
    box.innerHTML = '';
    return;
  }

  fetch('/grooming/receptionist/customers/search?q=' + encodeURIComponent(q))
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (!data.ok || !data.customers || data.customers.length === 0) {
        box.innerHTML = '<div style="padding: 0.5rem 0.75rem; color: #6b7280; font-size: 0.85rem;">No matching registered customers</div>';
        box.style.display = 'block';
        return;
      }
      var html = '';
      data.customers.forEach(function(c) {
        // Strict format requirement: `Customer Name #CustomerID`
        html += '<div onclick="selectRegisteredCustomer(' + c.id + ', \'' + escapeHtml(c.name) + '\', \'' + escapeHtml(c.label) + '\')" style="padding: 0.5rem 0.75rem; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 0.875rem;" onmouseover="this.style.background=\'#f9fafb\'" onmouseout="this.style.background=\'#fff\'">' +
                  '<strong>' + escapeHtml(c.label) + '</strong>' +
                  (c.phone ? ' <span style="color: #6b7280; font-size: 0.75rem;">(' + escapeHtml(c.phone) + ')</span>' : '') +
                '</div>';
      });
      box.innerHTML = html;
      box.style.display = 'block';
    });
}

function selectRegisteredCustomer(id, name, label) {
  document.getElementById('registered-search-input').value = label;
  document.getElementById('registered-customer-id-display').value = '#' + id;
  document.getElementById('registered-customer-id-hidden').value = id;
  document.getElementById('registered-suggestions').style.display = 'none';

  // Load customer's registered pets
  fetch('/grooming/receptionist/customers/' + id + '/pets')
    .then(function(res) { return res.json(); })
    .then(function(data) {
      registeredPetsList = data.pets || [];
      // Clear previously selected pets when customer changes
      document.getElementById('pets-container').innerHTML = '';
      petCount = 0;
      addPetEntry();
    });
}

function addPetEntry() {
  petCount++;
  var container = document.getElementById('pets-container');
  var div = document.createElement('div');
  div.id = 'pet-card-' + petCount;
  div.style.cssText = 'background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; position: relative;';

  var headerHtml = '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.65rem;">' +
                     '<span style="font-weight: 700; font-size: 0.9rem; color: #111827;">Pet #' + petCount + '</span>' +
                     (petCount > 1 ? '<button type="button" class="btn ghost btn-sm" onclick="removePetEntry(' + petCount + ')" style="color: #ef4444; font-size: 0.75rem;">Remove Pet</button>' : '') +
                   '</div>';

  var petInputHtml = '';
  if (currentCustomerType === 'guest') {
    petInputHtml = '<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">' +
                     '<div>' +
                       '<label class="caption" style="display: block; font-weight: 600; margin-bottom: 0.2rem;">Pet Name *</label>' +
                       '<input type="text" name="pets[' + petCount + '][name]" placeholder="e.g. Bella" required style="width: 100%;">' +
                     '</div>' +
                     '<div>' +
                       '<label class="caption" style="display: block; font-weight: 600; margin-bottom: 0.2rem;">Species *</label>' +
                       '<select name="pets[' + petCount + '][species]" style="width: 100%;">' +
                         '<option value="Dog">Dog</option>' +
                         '<option value="Cat">Cat</option>' +
                         '<option value="Rabbit">Rabbit</option>' +
                         '<option value="Other">Other</option>' +
                       '</select>' +
                     '</div>' +
                   '</div>';
  } else {
    var petOptions = '<option value="">-- Choose Registered Pet --</option>';
    registeredPetsList.forEach(function(rp) {
      petOptions += '<option value="' + rp.pet_id + '">' + escapeHtml(rp.pet_name) + ' (' + escapeHtml(rp.species) + ' - ' + (rp.breed || 'Breed') + ')</option>';
    });
    petInputHtml = '<div style="margin-bottom: 0.75rem;">' +
                     '<label class="caption" style="display: block; font-weight: 600; margin-bottom: 0.2rem;">Select Customer Pet *</label>' +
                     '<select name="pets[' + petCount + '][pet_id]" required style="width: 100%;">' + petOptions + '</select>' +
                   '</div>';
  }

  // Services Checkboxes per Pet
  var svcCheckboxes = '<label class="caption" style="display: block; font-weight: 600; margin-bottom: 0.35rem;">Select Services for this Pet *</label>' +
                      '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; background: #fff; padding: 0.5rem; border-radius: 6px; border: 1px solid #e5e7eb;">';
  GROOMING_SERVICES.forEach(function(s) {
    svcCheckboxes += '<label style="display: flex; align-items: flex-start; gap: 0.35rem; font-size: 0.8rem; cursor: pointer;">' +
                       '<input type="checkbox" name="pets[' + petCount + '][services][]" value="' + s.service_id + '" onchange="calculateWalkinDuration()">' +
                       '<span>' + escapeHtml(s.service_name) + ' <strong style="color: #0e8a75;">₱' + parseFloat(s.base_price).toFixed(2) + '</strong></span>' +
                     '</label>';
  });
  svcCheckboxes += '</div>';

  div.innerHTML = headerHtml + petInputHtml + svcCheckboxes;
  container.appendChild(div);
  calculateWalkinDuration();
}

function removePetEntry(id) {
  var el = document.getElementById('pet-card-' + id);
  if (el) el.remove();
  calculateWalkinDuration();
}

function calculateWalkinDuration() {
  var total = 0;
  var checkboxes = document.querySelectorAll('#pets-container input[type="checkbox"]:checked');
  checkboxes.forEach(function(cb) {
    var sid = parseInt(cb.value, 10);
    var found = GROOMING_SERVICES.find(function(s) { return parseInt(s.service_id, 10) === sid; });
    if (found) {
      total += parseInt(found.estimated_duration_minutes || 30, 10);
    }
  });
  if (total <= 0) total = 60;
  var el = document.getElementById('walkin-summary-duration');
  if (el) el.textContent = 'Estimated Total Duration: ~' + total + ' minutes';
}

function goToStep(step) {
  if (step === 2) {
    if (currentCustomerType === 'guest') {
      var name = document.getElementById('guest-name-input').value.trim();
      if (!name) {
        alert('Please enter guest customer name.');
        return;
      }
    } else {
      var cid = document.getElementById('registered-customer-id-hidden').value;
      if (!cid) {
        alert('Please search and select a registered customer.');
        return;
      }
    }
  }

  if (step === 3) {
    // Validate that at least one pet exists and each pet has at least 1 service
    var cards = document.querySelectorAll('#pets-container > div');
    if (cards.length === 0) {
      alert('Please add at least one pet.');
      return;
    }
    for (var i = 0; i < cards.length; i++) {
      var checked = cards[i].querySelectorAll('input[type="checkbox"]:checked');
      if (checked.length === 0) {
        alert('Please select at least one grooming service for each pet.');
        return;
      }
    }
  }

  document.getElementById('walkin-part-1').style.display = step === 1 ? 'block' : 'none';
  document.getElementById('walkin-part-2').style.display = step === 2 ? 'block' : 'none';
  document.getElementById('walkin-part-3').style.display = step === 3 ? 'block' : 'none';

  // Update pills
  [1, 2, 3].forEach(function(n) {
    var pill = document.querySelector('#step-pill-' + n + ' span:first-child');
    if (pill) {
      pill.style.background = n <= step ? '#0e8a75' : '#d1d5db';
    }
  });
}

function escapeHtml(str) {
  return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

