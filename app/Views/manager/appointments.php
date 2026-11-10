<?php require __DIR__ . '/_nav.php'; ?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Schedule Management</p>
    <h1>Grooming Appointments Calendar</h1>
    <p class="muted">7-day chronological weekly schedule for <?= e($unitName) ?>. Select any appointment block to inspect details and manage confirmed bookings.</p>
  </div>
</div>

<!-- Calendar Navigation Row: Previous and Next arrow buttons at opposite ends -->
<div class="card grooming-cal-toolbar" style="padding: 0.75rem 1.25rem; margin-bottom: 0.85rem;">
  <div class="grooming-cal-nav-row">
    <!-- Far Left: Previous 7-Day Range (arrow only: ‹) -->
    <a href="/manager/appointments<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>start=<?= e($prevWeekStart) ?>"
       class="btn secondary btn-sm grooming-cal-nav-btn"
       id="grooming-cal-prev"
       title="Previous 7 days"
       aria-label="Previous 7 days">&lsaquo;</a>

    <!-- Center: Today button, Active 7-Day Range Label, and Month Picker -->
    <div class="grooming-cal-nav-center">
      <a href="/manager/appointments<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>start=<?= e($todayWeekStart) ?>"
         class="btn <?= $isCurrentWeek ? 'secondary' : 'ghost' ?> btn-sm grooming-cal-today-btn"
         id="grooming-cal-today">
        Today
      </a>

      <strong class="grooming-cal-range-label" id="grooming-cal-range-label">
        <?= e($rangeHeading) ?>
      </strong>
      <span class="badge secondary" style="font-size: 0.76rem; font-weight: 600;" id="grooming-cal-overlap-badge" title="Maximum allowed appointment overlap configured for <?= e($unitName) ?>">
        Max Overlap: <?= (int)($maxOverlapMinutes ?? 15) ?>m
      </span>

      <form method="get" action="/manager/appointments" class="grooming-cal-month-form" id="grooming-cal-month-form">
        <?php if (!empty($isSuper)): ?>
          <input type="hidden" name="unit_id" value="<?= (int)$unitId ?>">
        <?php endif; ?>
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

    <!-- Far Right: Next 7-Day Range (arrow only: ›) -->
    <a href="/manager/appointments<?= $unitQuery ?><?= !empty($unitQuery) ? '&' : '?' ?>start=<?= e($nextWeekStart) ?>"
       class="btn secondary btn-sm grooming-cal-nav-btn"
       id="grooming-cal-next"
       title="Next 7 days"
       aria-label="Next 7 days">&rsaquo;</a>
  </div>
</div>

<!-- Google Calendar-inspired 7-Day Weekly Calendar -->
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
                    aria-controls="grooming-speech-bubble-<?= $apptId ?>">
              <strong class="grooming-cal-block-id">#<?= $apptId ?></strong>
              <span class="grooming-cal-block-time"><?= e($appt['time_coverage']) ?></span>
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
        $isConfirmedManageable = in_array($statusName, ['Confirmed', 'In Progress'], true);
        $isPendingRequest = in_array($statusName, ['Pending', 'Awaiting Customer Response'], true);
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

          <!-- Appointment Management Controls Scoped by Status & Permission Rules -->
          <div class="grooming-bubble-actions">
            <?php if ($isConfirmedManageable): ?>
              <!-- 1. Reassign Grooming Employee (with scheduling conflict validation) -->
              <form method="post" action="/manager/appointments/<?= $apptId ?>/reassign<?= $unitQuery ?>" class="grooming-bubble-form">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="return_start" value="<?= e($startDateStr) ?>">
                <label for="reassign-emp-<?= $apptId ?>" class="caption" style="font-weight: 600; display: block; margin-bottom: 0.25rem;">
                  Assigned Groomer / Stylist
                </label>
                <div style="display: flex; gap: 0.4rem;">
                  <select id="reassign-emp-<?= $apptId ?>" name="employee_id" required style="flex: 1; font-size: 0.84rem; padding: 0.35rem 0.5rem;">
                    <option value="">Select Groomer...</option>
                    <?php foreach ($groomingStylists as $st): ?>
                      <option value="<?= (int)$st['employee_id'] ?>" <?= (int)$appt['performer_employee_id'] === (int)$st['employee_id'] ? 'selected' : '' ?>>
                        <?= e($st['first_name'] . ' ' . $st['last_name'] . ' (' . $st['position'] . ')') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn secondary btn-sm">Reassign</button>
                </div>
              </form>

              <!-- 2. Confirmed Appointment Status Progression -->
              <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px solid var(--line);">
                <?php if ($statusName === 'Confirmed'): ?>
                  <form method="post" action="/manager/appointments/<?= $apptId ?>/status<?= $unitQuery ?>" style="margin: 0;">
                    <?= \App\Core\CSRF::field() ?>
                    <input type="hidden" name="return_start" value="<?= e($startDateStr) ?>">
                    <input type="hidden" name="status" value="In Progress">
                    <button type="submit" class="btn secondary btn-sm">Start In Progress</button>
                  </form>
                  <form method="post" action="/manager/appointments/<?= $apptId ?>/status<?= $unitQuery ?>" style="margin: 0;">
                    <?= \App\Core\CSRF::field() ?>
                    <input type="hidden" name="return_start" value="<?= e($startDateStr) ?>">
                    <input type="hidden" name="status" value="No-Show">
                    <button type="submit" class="btn ghost btn-sm">Mark No-Show</button>
                  </form>
                <?php endif; ?>

                <form method="post" action="/manager/appointments/<?= $apptId ?>/complete<?= $unitQuery ?>" style="margin: 0;">
                  <?= \App\Core\CSRF::field() ?>
                  <input type="hidden" name="return_start" value="<?= e($startDateStr) ?>">
                  <button type="submit" class="btn btn-sm">Mark Completed</button>
                </form>
              </div>

              <!-- 3. Cancel Appointment (Mandatory Reason) -->
              <form method="post" action="/manager/appointments/<?= $apptId ?>/cancel<?= $unitQuery ?>" class="grooming-bubble-form" style="margin-top: 0.6rem; padding-top: 0.6rem; border-top: 1px solid var(--line);">
                <?= \App\Core\CSRF::field() ?>
                <input type="hidden" name="return_start" value="<?= e($startDateStr) ?>">
                <label for="cancel-reason-<?= $apptId ?>" class="caption" style="font-weight: 600; display: block; margin-bottom: 0.25rem; color: var(--err);">
                  Cancel Confirmed Appointment (Reason Required)
                </label>
                <div style="display: flex; gap: 0.4rem;">
                  <input type="text"
                         id="cancel-reason-<?= $apptId ?>"
                         name="cancellation_reason"
                         required
                         placeholder="Enter mandatory cancellation reason..."
                         style="flex: 1; font-size: 0.84rem; padding: 0.35rem 0.55rem;">
                  <button type="submit" class="btn ghost btn-sm" style="color: var(--err); border-color: var(--err);">Cancel</button>
                </div>
              </form>
            <?php elseif ($isPendingRequest): ?>
              <div class="grooming-bubble-notice">
                <strong>Pending Customer Request:</strong> Initial intake confirmation and rejection of pending requests are handled by the Grooming Receptionist. Once confirmed, the Business Manager can reassign staff, update progress, or cancel the booking here.
              </div>
            <?php else: ?>
              <div class="grooming-bubble-notice">
                This appointment is in <strong><?= e($statusName) ?></strong> status and is archived as a historical record.
              </div>
            <?php endif; ?>
          </div>
        </div>
      </aside>
    <?php endforeach; ?>
  </div>
</div>

