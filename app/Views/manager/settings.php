<?php
require __DIR__ . '/_nav.php';
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($unitName) ?> Administration</p>
    <h1>Business Settings</h1>
    <p class="muted">Exclusive business manager configuration for <?= e($unitName) ?> profile, operating schedule, store policies, and payment transactions.</p>
  </div>
</div>

<!-- SECTION 1: BUSINESS PROFILE -->
<section class="card settings-section-card" id="business-profile-section">
  <div class="settings-card-head">
    <div>
      <h2 class="settings-card-title">1. Business Profile</h2>
      <p class="caption muted" style="margin: 0.15rem 0 0 0;">Store identity &amp; customer contact</p>
    </div>
    <span class="badge secondary" style="font-weight: 600;">Store Contact</span>
  </div>

  <form method="post" action="/manager/settings/profile<?= $unitQuery ?>">
    <?= \App\Core\CSRF::field() ?>

    <div class="settings-profile-grid">
      <div class="field" style="margin: 0;">
        <label for="prof-biz-name" style="display: block; font-weight: 600; font-size: 0.88rem; margin-bottom: 0.35rem;">Business Name *</label>
        <input id="prof-biz-name" name="business_name" type="text" required value="<?= e($settings['business_name'] ?? ('VETGRPET ' . $unitName)) ?>" style="width: 100%; height: 40px;">
      </div>

      <div class="field" style="margin: 0;">
        <label for="prof-biz-email" style="display: block; font-weight: 600; font-size: 0.88rem; margin-bottom: 0.35rem;">Business Email *</label>
        <input id="prof-biz-email" name="business_email" type="email" required value="<?= e($settings['business_email'] ?? (strtolower(str_replace(' ', '', $unitName)) . '@vetgrpet.local')) ?>" style="width: 100%; height: 40px;">
      </div>

      <div class="field" style="margin: 0;">
        <label for="prof-biz-phone" style="display: block; font-weight: 600; font-size: 0.88rem; margin-bottom: 0.35rem;">
          Contact Number * <span class="caption muted" style="font-weight: normal;">(8-digit landline or 11-digit mobile)</span>
        </label>
        <input id="prof-biz-phone" name="contact_number" type="text" required value="<?= e($settings['contact_number'] ?? '09175550100') ?>" placeholder="e.g. 81234567 or 09175550100" style="width: 100%; height: 40px;">
        <p class="caption" style="margin: 0.35rem 0 0 0; color: var(--muted); font-size: 0.78rem;">Accepts either an 8-digit Philippine landline (e.g. 81234567) or standard 11-digit phone number (e.g. 09175550100).</p>
      </div>
    </div>

    <div style="margin-top: 1.25rem; display: flex; justify-content: flex-end;">
      <button type="submit" class="btn primary" style="height: 40px; padding: 0 1.25rem; font-weight: 600;">Save Profile Settings</button>
    </div>
  </form>
</section>

<!-- SECTION 2: OPERATING HOURS -->
<section class="card settings-section-card" id="operating-hours-section">
  <div class="settings-card-head">
    <div>
      <h2 class="settings-card-title">2. Operating Hours</h2>
      <p class="caption muted" style="margin: 0.15rem 0 0 0;">Weekly schedule for 7 days (Monday through Sunday). Customers view these operating hours across store channels.</p>
    </div>
    <span class="badge secondary" style="font-weight: 600;">7-Day Schedule</span>
  </div>

  <form method="post" action="/manager/settings/hours<?= $unitQuery ?>">
    <?= \App\Core\CSRF::field() ?>

    <div class="table-wrap" style="overflow-x: auto; border: 1px solid var(--line); border-radius: var(--radius-md, 8px); margin-bottom: 1.25rem;">
      <table style="width: 100%; border-collapse: collapse; margin: 0; box-shadow: none; border: 0;">
        <thead>
          <tr style="background: var(--surface-subtle, #f8fafc); border-bottom: 1px solid var(--line);">
            <th style="padding: 0.75rem 1rem; width: 160px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 650;">Day of Week</th>
            <th style="padding: 0.75rem 1rem; width: 140px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 650;">Open / Closed</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 650;">Opening Time</th>
            <th style="padding: 0.75rem 1rem; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); font-weight: 650;">Closing Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($operatingHours as $h):
            $d = (int)$h['day_of_week'];
            $isOpen = !empty($h['is_open']);

            $oParts = explode(':', (string)$h['open_time']);
            $oH24 = (int)($oParts[0] ?? 8);
            $oM = sprintf('%02d', (int)($oParts[1] ?? 0));
            $oAmPm = $oH24 >= 12 ? 'PM' : 'AM';
            $oH12 = $oH24 % 12 ?: 12;

            $cParts = explode(':', (string)$h['close_time']);
            $cH24 = (int)($cParts[0] ?? 19);
            $cM = sprintf('%02d', (int)($cParts[1] ?? 0));
            $cAmPm = $cH24 >= 12 ? 'PM' : 'AM';
            $cH12 = $cH24 % 12 ?: 12;
          ?>
            <tr style="border-bottom: 1px solid var(--line);">
              <td style="padding: 0.75rem 1rem; vertical-align: middle;">
                <strong style="font-size: 0.92rem; color: var(--ink);"><?= e($h['day_name']) ?></strong>
                <input type="hidden" name="hours[<?= $d ?>][day_of_week]" value="<?= $d ?>">
                <input type="hidden" name="hours[<?= $d ?>][day_name]" value="<?= e($h['day_name']) ?>">
              </td>
              <td style="padding: 0.75rem 1rem; vertical-align: middle;">
                <label style="display: inline-flex; align-items: center; gap: 0.5rem; cursor: pointer; user-select: none;">
                  <input type="checkbox" name="hours[<?= $d ?>][is_open]" value="1" <?= $isOpen ? 'checked' : '' ?> class="day-open-toggle" data-day="<?= $d ?>" style="width: 17px; height: 17px; accent-color: var(--brand-emerald, #10b981); cursor: pointer;">
                  <span class="day-status-label" style="font-weight: 650; font-size: 0.88rem; color: <?= $isOpen ? 'var(--ok, #059669)' : 'var(--muted, #6b7280)' ?>;"><?= $isOpen ? 'Open' : 'Closed' ?></span>
                </label>
              </td>
              <td style="padding: 0.75rem 1rem; vertical-align: middle;">
                <div class="custom-time-picker <?= !$isOpen ? 'is-disabled' : '' ?>" data-day="<?= $d ?>" data-type="open">
                  <select class="time-select time-hour" aria-label="Opening hour for <?= e($h['day_name']) ?>" <?= !$isOpen ? 'disabled' : '' ?>>
                    <?php for ($hr = 1; $hr <= 12; $hr++): ?>
                      <option value="<?= $hr ?>" <?= $oH12 === $hr ? 'selected' : '' ?>><?= sprintf('%02d', $hr) ?></option>
                    <?php endfor; ?>
                  </select>
                  <span class="time-colon">:</span>
                  <select class="time-select time-minute" aria-label="Opening minute for <?= e($h['day_name']) ?>" <?= !$isOpen ? 'disabled' : '' ?>>
                    <?php foreach (['00', '15', '30', '45'] as $min): ?>
                      <option value="<?= $min ?>" <?= $oM === $min ? 'selected' : '' ?>><?= $min ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select class="time-select time-ampm" aria-label="Opening AM or PM for <?= e($h['day_name']) ?>" <?= !$isOpen ? 'disabled' : '' ?>>
                    <option value="AM" <?= $oAmPm === 'AM' ? 'selected' : '' ?>>AM</option>
                    <option value="PM" <?= $oAmPm === 'PM' ? 'selected' : '' ?>>PM</option>
                  </select>
                  <input type="hidden" name="hours[<?= $d ?>][open_time]" value="<?= e(substr((string)$h['open_time'], 0, 8)) ?>" class="day-time-input-<?= $d ?> time-hidden-val">
                </div>
              </td>
              <td style="padding: 0.75rem 1rem; vertical-align: middle;">
                <div class="custom-time-picker <?= !$isOpen ? 'is-disabled' : '' ?>" data-day="<?= $d ?>" data-type="close">
                  <select class="time-select time-hour" aria-label="Closing hour for <?= e($h['day_name']) ?>" <?= !$isOpen ? 'disabled' : '' ?>>
                    <?php for ($hr = 1; $hr <= 12; $hr++): ?>
                      <option value="<?= $hr ?>" <?= $cH12 === $hr ? 'selected' : '' ?>><?= sprintf('%02d', $hr) ?></option>
                    <?php endfor; ?>
                  </select>
                  <span class="time-colon">:</span>
                  <select class="time-select time-minute" aria-label="Closing minute for <?= e($h['day_name']) ?>" <?= !$isOpen ? 'disabled' : '' ?>>
                    <?php foreach (['00', '15', '30', '45'] as $min): ?>
                      <option value="<?= $min ?>" <?= $cM === $min ? 'selected' : '' ?>><?= $min ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select class="time-select time-ampm" aria-label="Closing AM or PM for <?= e($h['day_name']) ?>" <?= !$isOpen ? 'disabled' : '' ?>>
                    <option value="AM" <?= $cAmPm === 'AM' ? 'selected' : '' ?>>AM</option>
                    <option value="PM" <?= $cAmPm === 'PM' ? 'selected' : '' ?>>PM</option>
                  </select>
                  <input type="hidden" name="hours[<?= $d ?>][close_time]" value="<?= e(substr((string)$h['close_time'], 0, 8)) ?>" class="day-time-input-<?= $d ?> time-hidden-val">
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="display: flex; justify-content: flex-end;">
      <button type="submit" class="btn primary" style="height: 40px; padding: 0 1.25rem; font-weight: 600;">Update Operating Hours</button>
    </div>
  </form>
</section>

<!-- SECTION 3: BUSINESS POLICIES (READ-ONLY) -->
<section class="card settings-section-card" id="business-policies-section">
  <div class="settings-card-head">
    <div>
      <h2 class="settings-card-title">3. Business Policies</h2>
      <p class="caption muted" style="margin: 0.15rem 0 0 0;">Established <?= e($unitName) ?> operational guidelines, standards, and patient compliance rules</p>
    </div>
    <span class="badge secondary" style="font-weight: 600;">Read-Only</span>
  </div>

  <div class="settings-policies-grid">
    <?php if ((int)$unitId === \App\Models\Units::vetClinicId()): ?>
      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Clinical Consultation &amp; Triage Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Veterinary clinical consultations follow scheduled examination slots. Prior medical records and vaccination passports should be presented upon check-in. Critical emergency triage cases receive immediate clinical priority over routine consultations.
        </p>
      </article>

      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Prescription &amp; Medication Dispensing Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Prescription animal pharmaceuticals and regulated biologics are dispensed exclusively upon authorization by a licensed veterinarian. To prevent animal cross-contamination and guarantee drug efficacy, dispensed pharmaceuticals cannot be returned for resale.
        </p>
      </article>

      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Cancellation &amp; Rescheduling Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Medical appointments may be rescheduled or cancelled up to 24 hours prior to the scheduled time. Timely notice enables clinical staff to accommodate patients needing urgent consultation.
        </p>
      </article>

      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Diagnostic &amp; Procedure Consent Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Specialized diagnostic blood panels, dental scaling, sedation, and minor surgical procedures require prior owner consultation and informed consent from the patient's guardian.
        </p>
      </article>
    <?php else: ?>
      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Return &amp; Refund Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Pet retail items are eligible for refund or exchange within 7 days of purchase upon presentation of valid receipt. Returned items must be undamaged, unopened, and undergo physical inspection before being restored to sellable inventory.
        </p>
      </article>

      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Pet Safety &amp; Perishable Feeds Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Perishable animal feeds, prescription veterinary supplements, and opened treats cannot be returned for resale to prevent pet cross-contamination. Damaged or expired inventory must be processed via decrease stock adjustment.
        </p>
      </article>

      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Store Order &amp; Cancellation Policy
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Online delivery and in-store pickup orders may be cancelled prior to dispatch. Cancelled orders automatically release reserved inventory allocations.
        </p>
      </article>

      <article style="background: var(--surface-subtle, #f8fafc); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-md, 8px); padding: 1rem 1.15rem; display: flex; flex-direction: column; gap: 0.35rem;">
        <strong style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; font-weight: 650; color: var(--ink, #1e293b);">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand-emerald, #059669); flex-shrink: 0;" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          Payment &amp; Transaction Verification
        </strong>
        <p class="caption" style="margin: 0; line-height: 1.5; color: var(--muted, #475569); font-size: 0.84rem;">
          Electronic e-wallet settlements (GCash and Maya) require two-factor phone number verification before merchant receiving channels are activated.
        </p>
      </article>
    <?php endif; ?>
  </div>
</section>

<!-- SECTION 4: TRANSACTION SETTINGS -->
<section class="card settings-section-card" id="transaction-settings-section">
  <div class="settings-card-head">
    <div>
      <h2 class="settings-card-title">4. Transaction Settings</h2>
      <p class="caption muted" style="margin: 0.15rem 0 0 0;">Supported <?= e($unitName) ?> payment gateways: GCash, Maya, and Cash on Delivery (COD). E-wallets require server-side OTP verification.</p>
    </div>
    <span class="badge secondary" style="font-weight: 600;">3 Gateways</span>
  </div>

  <div class="settings-gateways-grid">
    <!-- GCASH SETUP FORM -->
    <div class="settings-gateway-card">
      <div>
        <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #005ce6;">GCash Gateway</h3>
          <span class="badge <?= !empty($settings['gcash_verified']) ? 'badge-success' : 'secondary' ?>" id="gcash-badge" style="font-weight: 600;">
            <?= !empty($settings['gcash_verified']) ? 'Verified' : 'Unverified' ?>
          </span>
        </div>
        <p class="caption muted" style="margin: 0 0 1rem 0; line-height: 1.45; font-size: 0.82rem;">
          Enter mobile number, request OTP, verify code, then confirm merchant configuration.
        </p>

        <form method="post" action="/manager/settings/payment<?= $unitQuery ?>" id="gcash-setup-form">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="service_type" value="gcash">

          <div class="field" style="margin-bottom: 0.85rem;">
            <label for="gcash-mobile" style="display: block; font-weight: 600; font-size: 0.84rem; margin-bottom: 0.3rem;">GCash Mobile Number *</label>
            <div class="row" style="gap: 0.4rem; align-items: stretch; flex-wrap: nowrap;">
              <input id="gcash-mobile" name="mobile_number" type="tel" value="<?= e($settings['gcash_mobile'] ?? '') ?>" placeholder="0917XXXXXXX" style="flex: 1 1 auto; min-width: 120px; height: 38px;" required>
              <button type="button" class="btn secondary btn-sm" id="gcash-send-otp-btn" style="height: 38px; padding: 0 0.85rem; font-size: 0.82rem; font-weight: 600; white-space: nowrap; flex-shrink: 0;" disabled>Send OTP</button>
            </div>
            <div id="gcash-otp-notice" class="caption" style="margin-top: 0.35rem; color: var(--primary, #0284c7); font-size: 0.78rem; min-height: 1.15rem;"></div>
          </div>

          <div class="field" style="margin-bottom: 0;">
            <label for="gcash-otp" style="display: block; font-weight: 600; font-size: 0.84rem; margin-bottom: 0.3rem;">6-Digit OTP *</label>
            <div class="row" style="gap: 0.4rem; align-items: stretch; flex-wrap: nowrap;">
              <input id="gcash-otp" name="otp" type="text" maxlength="6" placeholder="6-digit code" style="flex: 1 1 auto; min-width: 120px; height: 38px;" disabled autocomplete="off">
              <button type="button" class="btn secondary btn-sm" id="gcash-verify-otp-btn" style="height: 38px; padding: 0 0.85rem; font-size: 0.82rem; font-weight: 600; white-space: nowrap; flex-shrink: 0;" disabled>Verify OTP</button>
            </div>
            <div id="gcash-verify-status" class="caption" style="margin-top: 0.35rem; font-size: 0.78rem; min-height: 1.15rem;"></div>
          </div>

          <!-- Hidden confirmation flag (server checks OTP verification table regardless) -->
          <input type="hidden" id="gcash-is-verified" name="is_verified" value="<?= !empty($settings['gcash_verified']) ? '1' : '0' ?>">

          <div style="margin-top: 1.25rem;">
            <button type="submit" class="btn primary" id="gcash-confirm-btn" style="width: 100%; height: 40px; font-weight: 600;" <?= empty($settings['gcash_verified']) ? 'disabled' : '' ?>>
              Confirm GCash Setup
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MAYA SETUP FORM -->
    <div class="settings-gateway-card">
      <div>
        <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: #16a34a;">Maya Gateway</h3>
          <span class="badge <?= !empty($settings['maya_verified']) ? 'badge-success' : 'secondary' ?>" id="maya-badge" style="font-weight: 600;">
            <?= !empty($settings['maya_verified']) ? 'Verified' : 'Unverified' ?>
          </span>
        </div>
        <p class="caption muted" style="margin: 0 0 1rem 0; line-height: 1.45; font-size: 0.82rem;">
          Separate merchant account. Enter mobile number, request OTP, verify code, then confirm.
        </p>

        <form method="post" action="/manager/settings/payment<?= $unitQuery ?>" id="maya-setup-form">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="service_type" value="maya">

          <div class="field" style="margin-bottom: 0.85rem;">
            <label for="maya-mobile" style="display: block; font-weight: 600; font-size: 0.84rem; margin-bottom: 0.3rem;">Maya Mobile Number *</label>
            <div class="row" style="gap: 0.4rem; align-items: stretch; flex-wrap: nowrap;">
              <input id="maya-mobile" name="mobile_number" type="tel" value="<?= e($settings['maya_mobile'] ?? '') ?>" placeholder="0918XXXXXXX" style="flex: 1 1 auto; min-width: 120px; height: 38px;" required>
              <button type="button" class="btn secondary btn-sm" id="maya-send-otp-btn" style="height: 38px; padding: 0 0.85rem; font-size: 0.82rem; font-weight: 600; white-space: nowrap; flex-shrink: 0;" disabled>Send OTP</button>
            </div>
            <div id="maya-otp-notice" class="caption" style="margin-top: 0.35rem; color: var(--primary, #0284c7); font-size: 0.78rem; min-height: 1.15rem;"></div>
          </div>

          <div class="field" style="margin-bottom: 0;">
            <label for="maya-otp" style="display: block; font-weight: 600; font-size: 0.84rem; margin-bottom: 0.3rem;">6-Digit OTP *</label>
            <div class="row" style="gap: 0.4rem; align-items: stretch; flex-wrap: nowrap;">
              <input id="maya-otp" name="otp" type="text" maxlength="6" placeholder="6-digit code" style="flex: 1 1 auto; min-width: 120px; height: 38px;" disabled autocomplete="off">
              <button type="button" class="btn secondary btn-sm" id="maya-verify-otp-btn" style="height: 38px; padding: 0 0.85rem; font-size: 0.82rem; font-weight: 600; white-space: nowrap; flex-shrink: 0;" disabled>Verify OTP</button>
            </div>
            <div id="maya-verify-status" class="caption" style="margin-top: 0.35rem; font-size: 0.78rem; min-height: 1.15rem;"></div>
          </div>

          <input type="hidden" id="maya-is-verified" name="is_verified" value="<?= !empty($settings['maya_verified']) ? '1' : '0' ?>">

          <div style="margin-top: 1.25rem;">
            <button type="submit" class="btn primary" id="maya-confirm-btn" style="width: 100%; height: 40px; font-weight: 600;" <?= empty($settings['maya_verified']) ? 'disabled' : '' ?>>
              Confirm Maya Setup
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- CASH ON DELIVERY (COD) -->
    <div class="settings-gateway-card">
      <div>
        <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.05rem; font-weight: 700; color: var(--ink, #1f2937);">Cash on Delivery</h3>
          <span class="badge <?= !empty($settings['cod_enabled']) ? 'badge-success' : 'secondary' ?>" style="font-weight: 600;">
            <?= !empty($settings['cod_enabled']) ? 'Enabled' : 'Disabled' ?>
          </span>
        </div>
        <p class="caption muted" style="margin: 0 0 1rem 0; line-height: 1.45; font-size: 0.82rem;">
          COD allows customers to pay in cash upon physical receipt. Does not require e-wallet mobile number or OTP verification.
        </p>

        <form method="post" action="/manager/settings/payment<?= $unitQuery ?>">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="service_type" value="cod">

          <label style="display: flex; gap: 0.65rem; align-items: center; cursor: pointer; padding: 0.85rem 1rem; background: var(--surface, #ffffff); border: 1px solid var(--line, #e2e8f0); border-radius: var(--radius-sm, 6px); margin-bottom: 1.5rem; user-select: none;">
            <input type="checkbox" name="cod_enabled" value="1" <?= !empty($settings['cod_enabled']) ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: var(--brand-emerald, #10b981); cursor: pointer;">
            <span style="font-weight: 600; font-size: 0.88rem; color: var(--ink);">Enable Cash on Delivery for <?= e($unitName) ?></span>
          </label>

          <button type="submit" class="btn primary" style="width: 100%; height: 40px; font-weight: 600;">
            Update COD Status
          </button>
        </form>
      </div>
    </div>
  </div>
</section>
