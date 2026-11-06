<?php
require __DIR__ . '/_nav.php';
?>

<div class="page-head">
  <div>
    <p class="eyebrow">Business Administration</p>
    <h1>Business Settings</h1>
    <p class="muted">Exclusive business manager configuration for Pet Store profile, operating schedule, store policies, and payment transactions.</p>
  </div>
</div>

<div class="grid grid-2" style="align-items: start; gap: 1.5rem; margin-bottom: 2rem;">
  <!-- SECTION 1: BUSINESS PROFILE -->
  <section class="card" id="business-profile-section">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <h2 style="margin: 0;">1. Business Profile</h2>
      <span class="caption">Store identity &amp; customer contact</span>
    </div>

    <form method="post" action="/manager/settings/profile<?= $unitQuery ?>">
      <?= \App\Core\CSRF::field() ?>

      <div class="field">
        <label for="prof-biz-name">Business Name *</label>
        <input id="prof-biz-name" name="business_name" type="text" required value="<?= e($settings['business_name'] ?? 'VETGRPET Pet Store') ?>">
      </div>

      <div class="field">
        <label for="prof-biz-email">Business Email *</label>
        <input id="prof-biz-email" name="business_email" type="email" required value="<?= e($settings['business_email'] ?? 'petstore@vetgrpet.local') ?>">
      </div>

      <div class="field">
        <label for="prof-biz-phone">Contact Number * (Must be 8-digit landline or 11-digit mobile)</label>
        <input id="prof-biz-phone" name="contact_number" type="text" required value="<?= e($settings['contact_number'] ?? '09175550100') ?>" placeholder="e.g. 81234567 or 09175550100">
        <p class="caption" style="margin-top: 0.35rem;">Accepts either an 8-digit Philippine landline (e.g. 81234567) or standard 11-digit phone number (e.g. 09175550100).</p>
      </div>

      <div style="margin-top: 1.5rem;">
        <button type="submit" class="btn primary">Save Profile Settings</button>
      </div>
    </form>
  </section>

  <!-- SECTION 3: BUSINESS POLICIES (READ-ONLY) -->
  <section class="card" id="business-policies-section">
    <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
      <h2 style="margin: 0;">3. Business Policies</h2>
      <span class="badge secondary">Read-Only</span>
    </div>

    <div style="display: flex; flex-direction: column; gap: 1rem;">
      <article style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem 1rem; border-radius: 6px;">
        <strong style="display: block; margin-bottom: 0.25rem;">Return &amp; Refund Policy</strong>
        <p class="caption" style="margin: 0; line-height: 1.45;">
          Pet retail items are eligible for refund or exchange within 7 days of purchase upon presentation of valid receipt. Returned items must be undamaged, unopened, and undergo physical inspection before being restored to sellable inventory.
        </p>
      </article>

      <article style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem 1rem; border-radius: 6px;">
        <strong style="display: block; margin-bottom: 0.25rem;">Pet Safety &amp; Perishable Feeds Policy</strong>
        <p class="caption" style="margin: 0; line-height: 1.45;">
          Perishable animal feeds, prescription veterinary supplements, and opened treats cannot be returned for resale to prevent pet cross-contamination. Damaged or expired inventory must be processed via decrease stock adjustment.
        </p>
      </article>

      <article style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem 1rem; border-radius: 6px;">
        <strong style="display: block; margin-bottom: 0.25rem;">Store Order &amp; Cancellation Policy</strong>
        <p class="caption" style="margin: 0; line-height: 1.45;">
          Online delivery and in-store pickup orders may be cancelled prior to dispatch. Cancelled orders automatically release reserved inventory allocations.
        </p>
      </article>

      <article style="background: var(--bg-subtle, #f9fafb); padding: 0.85rem 1rem; border-radius: 6px;">
        <strong style="display: block; margin-bottom: 0.25rem;">Payment &amp; Transaction Verification</strong>
        <p class="caption" style="margin: 0; line-height: 1.45;">
          Electronic e-wallet settlements (GCash and Maya) require two-factor phone number verification before merchant receiving channels are activated.
        </p>
      </article>
    </div>
  </section>
</div>

<!-- SECTION 2: OPERATING HOURS -->
<section class="card" id="operating-hours-section" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0;">2. Operating Hours</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">Weekly schedule for 7 days (Monday through Sunday). Customers view these operating hours across store channels.</p>
    </div>
    <span class="caption">7-Day Schedule</span>
  </div>

  <form method="post" action="/manager/settings/hours<?= $unitQuery ?>">
    <?= \App\Core\CSRF::field() ?>

    <div class="table-wrap" style="margin-bottom: 1.5rem;">
      <table>
        <thead>
          <tr>
            <th style="width: 140px;">Day of Week</th>
            <th style="width: 120px;">Open / Closed</th>
            <th>Opening Time</th>
            <th>Closing Time</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($operatingHours as $h):
            $d = (int)$h['day_of_week'];
            $isOpen = !empty($h['is_open']);
          ?>
            <tr>
              <td>
                <strong><?= e($h['day_name']) ?></strong>
                <input type="hidden" name="hours[<?= $d ?>][day_of_week]" value="<?= $d ?>">
                <input type="hidden" name="hours[<?= $d ?>][day_name]" value="<?= e($h['day_name']) ?>">
              </td>
              <td>
                <label style="display: inline-flex; align-items: center; gap: 0.4rem; cursor: pointer;">
                  <input type="checkbox" name="hours[<?= $d ?>][is_open]" value="1" <?= $isOpen ? 'checked' : '' ?> class="day-open-toggle" data-day="<?= $d ?>">
                  <span class="day-status-label" style="font-weight: 500; font-size: 0.9rem;"><?= $isOpen ? 'Open' : 'Closed' ?></span>
                </label>
              </td>
              <td>
                <input type="time" name="hours[<?= $d ?>][open_time]" value="<?= e(substr((string)$h['open_time'], 0, 5)) ?>" style="width: 140px;" class="day-time-input-<?= $d ?>" <?= !$isOpen ? 'disabled' : '' ?>>
              </td>
              <td>
                <input type="time" name="hours[<?= $d ?>][close_time]" value="<?= e(substr((string)$h['close_time'], 0, 5)) ?>" style="width: 140px;" class="day-time-input-<?= $d ?>" <?= !$isOpen ? 'disabled' : '' ?>>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div>
      <button type="submit" class="btn primary">Update Operating Hours</button>
    </div>
  </form>
</section>

<!-- SECTION 4: TRANSACTION SETTINGS -->
<section class="card" id="transaction-settings-section" style="margin-bottom: 2rem;">
  <div class="row" style="justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
    <div>
      <h2 style="margin: 0;">4. Transaction Settings</h2>
      <p class="caption" style="margin: 0.2rem 0 0 0;">Supported Pet Store payment gateways: GCash, Maya, and Cash on Delivery (COD). E-wallets require server-side OTP verification.</p>
    </div>
    <span class="caption">3 Gateways</span>
  </div>

  <div class="grid grid-3" style="gap: 1.5rem; align-items: stretch;">
    <!-- GCASH SETUP FORM -->
    <div style="background: var(--bg-subtle, #f9fafb); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border, #e5e7eb); display: flex; flex-direction: column; justify-content: space-between;">
      <div>
        <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.1rem; color: #005ce6;">GCash Gateway</h3>
          <span class="badge <?= !empty($settings['gcash_verified']) ? 'badge-success' : 'secondary' ?>" id="gcash-badge">
            <?= !empty($settings['gcash_verified']) ? 'Verified' : 'Unverified' ?>
          </span>
        </div>
        <p class="caption" style="margin-bottom: 1rem;">
          Enter mobile number, request OTP, verify code, then confirm merchant configuration.
        </p>

        <form method="post" action="/manager/settings/payment<?= $unitQuery ?>" id="gcash-setup-form">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="service_type" value="gcash">

          <div class="field">
            <label for="gcash-mobile">GCash Mobile Number *</label>
            <div class="row" style="gap: 0.4rem;">
              <input id="gcash-mobile" name="mobile_number" type="tel" value="<?= e($settings['gcash_mobile'] ?? '') ?>" placeholder="0917XXXXXXX" style="flex: 1;" required>
              <button type="button" class="btn secondary btn-sm" id="gcash-send-otp-btn" disabled>Send OTP</button>
            </div>
            <div id="gcash-otp-notice" class="caption" style="margin-top: 0.35rem; color: var(--primary, #0284c7);"></div>
          </div>

          <div class="field" style="margin-top: 1rem;">
            <label for="gcash-otp">6-Digit OTP *</label>
            <div class="row" style="gap: 0.4rem;">
              <input id="gcash-otp" name="otp" type="text" maxlength="6" placeholder="6-digit code" style="flex: 1;" disabled autocomplete="off">
              <button type="button" class="btn secondary btn-sm" id="gcash-verify-otp-btn" disabled>Verify OTP</button>
            </div>
            <div id="gcash-verify-status" class="caption" style="margin-top: 0.35rem;"></div>
          </div>

          <!-- Hidden confirmation flag (server checks OTP verification table regardless) -->
          <input type="hidden" id="gcash-is-verified" name="is_verified" value="<?= !empty($settings['gcash_verified']) ? '1' : '0' ?>">

          <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn primary" id="gcash-confirm-btn" style="width: 100%;" <?= empty($settings['gcash_verified']) ? 'disabled' : '' ?>>
              Confirm GCash Setup
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- MAYA SETUP FORM -->
    <div style="background: var(--bg-subtle, #f9fafb); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border, #e5e7eb); display: flex; flex-direction: column; justify-content: space-between;">
      <div>
        <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.1rem; color: #16a34a;">Maya Gateway</h3>
          <span class="badge <?= !empty($settings['maya_verified']) ? 'badge-success' : 'secondary' ?>" id="maya-badge">
            <?= !empty($settings['maya_verified']) ? 'Verified' : 'Unverified' ?>
          </span>
        </div>
        <p class="caption" style="margin-bottom: 1rem;">
          Separate merchant account. Enter mobile number, request OTP, verify code, then confirm.
        </p>

        <form method="post" action="/manager/settings/payment<?= $unitQuery ?>" id="maya-setup-form">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="service_type" value="maya">

          <div class="field">
            <label for="maya-mobile">Maya Mobile Number *</label>
            <div class="row" style="gap: 0.4rem;">
              <input id="maya-mobile" name="mobile_number" type="tel" value="<?= e($settings['maya_mobile'] ?? '') ?>" placeholder="0918XXXXXXX" style="flex: 1;" required>
              <button type="button" class="btn secondary btn-sm" id="maya-send-otp-btn" disabled>Send OTP</button>
            </div>
            <div id="maya-otp-notice" class="caption" style="margin-top: 0.35rem; color: var(--primary, #0284c7);"></div>
          </div>

          <div class="field" style="margin-top: 1rem;">
            <label for="maya-otp">6-Digit OTP *</label>
            <div class="row" style="gap: 0.4rem;">
              <input id="maya-otp" name="otp" type="text" maxlength="6" placeholder="6-digit code" style="flex: 1;" disabled autocomplete="off">
              <button type="button" class="btn secondary btn-sm" id="maya-verify-otp-btn" disabled>Verify OTP</button>
            </div>
            <div id="maya-verify-status" class="caption" style="margin-top: 0.35rem;"></div>
          </div>

          <input type="hidden" id="maya-is-verified" name="is_verified" value="<?= !empty($settings['maya_verified']) ? '1' : '0' ?>">

          <div style="margin-top: 1.5rem;">
            <button type="submit" class="btn primary" id="maya-confirm-btn" style="width: 100%;" <?= empty($settings['maya_verified']) ? 'disabled' : '' ?>>
              Confirm Maya Setup
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- CASH ON DELIVERY (COD) -->
    <div style="background: var(--bg-subtle, #f9fafb); padding: 1.25rem; border-radius: 8px; border: 1px solid var(--border, #e5e7eb); display: flex; flex-direction: column; justify-content: space-between;">
      <div>
        <div class="row" style="justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
          <h3 style="margin: 0; font-size: 1.1rem; color: var(--ink, #1f2937);">Cash on Delivery</h3>
          <span class="badge <?= !empty($settings['cod_enabled']) ? 'badge-success' : 'secondary' ?>">
            <?= !empty($settings['cod_enabled']) ? 'Enabled' : 'Disabled' ?>
          </span>
        </div>
        <p class="caption" style="margin-bottom: 1rem;">
          COD allows customers to pay in cash upon physical receipt. Does not require e-wallet mobile number or OTP verification.
        </p>

        <form method="post" action="/manager/settings/payment<?= $unitQuery ?>">
          <?= \App\Core\CSRF::field() ?>
          <input type="hidden" name="service_type" value="cod">

          <label style="display: flex; gap: 0.5rem; align-items: center; cursor: pointer; padding: 1rem; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 6px; margin-bottom: 1.5rem;">
            <input type="checkbox" name="cod_enabled" value="1" <?= !empty($settings['cod_enabled']) ? 'checked' : '' ?> style="transform: scale(1.15);">
            <span style="font-weight: 500;">Enable Cash on Delivery for Pet Store</span>
          </label>

          <button type="submit" class="btn primary" style="width: 100%;">
            Update COD Status
          </button>
        </form>
      </div>
    </div>
  </div>
</section>
