<?php
$stage = (int)($unlockedStage ?? 1);
if (!empty($errors)) {
    $stage = 4;
}
?>
<section class="auth-shell">
  <div class="auth-card">
    <div class="step-tracker" aria-label="Registration progress">
      <div class="step-chip <?= $stage > 1 ? 'is-done' : 'is-active' ?>" data-step-chip="1">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">1. Account Email</span>
      </div>
      <div class="step-chip <?= $stage > 2 ? 'is-done' : ($stage === 2 ? 'is-active' : '') ?>" data-step-chip="2">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">2. Full Name</span>
      </div>
      <div class="step-chip <?= $stage > 3 ? 'is-done' : ($stage === 3 ? 'is-active' : '') ?>" data-step-chip="3">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">3. Details</span>
      </div>
      <div class="step-chip <?= $stage === 4 ? 'is-active' : '' ?>" data-step-chip="4">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">4. Set Password</span>
      </div>
    </div>

    <h1>Create your account</h1>
    <p class="muted" style="margin-bottom:1.35rem">Complete each step and click Continue to unlock the next section.</p>

    <form method="post" action="/register" data-progressive-register novalidate>
      <?= \App\Core\CSRF::field() ?>

      <!-- Step 1: Account Email -->
      <div id="reg-stage-1" data-progressive-stage="1">
        <div class="progressive-stage-header">
          <span class="stage-pill">Step 1 · Account Email</span>
          <span class="stage-status-badge" data-stage1-done <?= $stage > 1 ? '' : 'hidden' ?>>Completed</span>
        </div>
        <div class="field">
          <div class="field-label-row">
            <label for="email" style="margin:0">Email address</label>
            <span class="label-optional">Required</span>
          </div>
          <input id="email" name="email" type="email" autocomplete="email" placeholder="name@example.com" required <?= $stage === 1 ? 'autofocus' : '' ?> value="<?= e($email ?? '') ?>">
          <div class="field-error" data-reg-email-error <?= isset($errors['email']) ? '' : 'hidden' ?>><?= e($errors['email'] ?? '') ?></div>
        </div>
        <div class="stage-actions" data-reg-step1-actions <?= $stage >= 2 ? 'hidden' : '' ?>>
          <button type="button" class="btn full" data-reg-unlock-2>Continue</button>
        </div>
      </div>

      <!-- Step 2: Full Name (First, Middle, Last aligned evenly) -->
      <div id="reg-stage-2" class="progressive-stage" data-progressive-stage="2" <?= $stage >= 2 ? '' : 'hidden' ?>>
        <div class="progressive-stage-header">
          <span class="stage-pill">Step 2 · Full Name</span>
          <span class="stage-status-badge" data-stage2-done <?= $stage > 2 ? '' : 'hidden' ?>>Completed</span>
        </div>
        <div class="form-grid-3">
          <div class="field">
            <div class="field-label-row">
              <label for="first_name" style="margin:0">First name</label>
            </div>
            <input id="first_name" name="first_name" type="text" autocomplete="given-name" placeholder="First" required value="<?= e($old['first_name'] ?? '') ?>">
            <?php if (isset($errors['first_name'])): ?><div class="field-error"><?= e($errors['first_name']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <div class="field-label-row">
              <label for="middle_name" style="margin:0">Middle name</label>
              <span class="label-optional">Optional</span>
            </div>
            <input id="middle_name" name="middle_name" type="text" autocomplete="additional-name" placeholder="Middle" value="<?= e($old['middle_name'] ?? '') ?>">
            <?php if (isset($errors['middle_name'])): ?><div class="field-error"><?= e($errors['middle_name']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <div class="field-label-row">
              <label for="last_name" style="margin:0">Last name</label>
            </div>
            <input id="last_name" name="last_name" type="text" autocomplete="family-name" placeholder="Last" required value="<?= e($old['last_name'] ?? '') ?>">
            <?php if (isset($errors['last_name'])): ?><div class="field-error"><?= e($errors['last_name']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="field-error" data-reg-name-error hidden></div>
        <div class="stage-actions" data-reg-step2-actions <?= $stage >= 3 ? 'hidden' : '' ?>>
          <button type="button" class="btn full" data-reg-unlock-3>Continue</button>
        </div>
      </div>

      <!-- Step 3: Additional Details (Never auto-reveals Step 4 while typing) -->
      <div id="reg-stage-3" class="progressive-stage" data-progressive-stage="3" <?= $stage >= 3 ? '' : 'hidden' ?>>
        <div class="progressive-stage-header">
          <span class="stage-pill">Step 3 · Additional Details</span>
          <span class="stage-status-badge" data-stage3-done <?= $stage > 3 ? '' : 'hidden' ?>>Completed</span>
        </div>
        <div class="form-grid-2">
          <div class="field">
            <div class="field-label-row">
              <label for="phone" style="margin:0">Phone number</label>
              <span class="label-optional">Optional</span>
            </div>
            <input id="phone" name="phone" type="text" autocomplete="tel" placeholder="555-0100" value="<?= e($old['phone'] ?? '') ?>">
            <div class="field-error" data-reg-phone-error <?= isset($errors['phone']) ? '' : 'hidden' ?>><?= e($errors['phone'] ?? '') ?></div>
          </div>
          <div class="field">
            <div class="field-label-row">
              <label for="address_line1" style="margin:0">Street address</label>
              <span class="label-optional">Optional</span>
            </div>
            <input id="address_line1" name="address_line1" type="text" autocomplete="address-line1" placeholder="123 Main St" value="<?= e($old['address_line1'] ?? '') ?>">
            <?php if (isset($errors['address_line1'])): ?><div class="field-error"><?= e($errors['address_line1']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="form-grid-3" style="margin-top:0.85rem">
          <div class="field">
            <div class="field-label-row">
              <label for="city" style="margin:0">City</label>
              <span class="label-optional">Optional</span>
            </div>
            <input id="city" name="city" type="text" autocomplete="address-level2" placeholder="City" value="<?= e($old['city'] ?? '') ?>">
            <?php if (isset($errors['city'])): ?><div class="field-error"><?= e($errors['city']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <div class="field-label-row">
              <label for="province" style="margin:0">Province / State</label>
              <span class="label-optional">Optional</span>
            </div>
            <input id="province" name="province" type="text" autocomplete="address-level1" placeholder="Province" value="<?= e($old['province'] ?? '') ?>">
          </div>
          <div class="field">
            <div class="field-label-row">
              <label for="postal_code" style="margin:0">Postal code</label>
              <span class="label-optional">Optional</span>
            </div>
            <input id="postal_code" name="postal_code" type="text" autocomplete="postal-code" placeholder="Postal code" value="<?= e($old['postal_code'] ?? '') ?>">
          </div>
        </div>
        <div class="stage-actions" data-reg-step3-actions <?= $stage >= 4 ? 'hidden' : '' ?>>
          <button type="button" class="btn full" data-reg-unlock-4>Continue</button>
        </div>
      </div>

      <!-- Step 4: Set Password & Live Confirmation -->
      <div id="reg-stage-4" class="progressive-stage" data-progressive-stage="4" <?= $stage >= 4 ? '' : 'hidden' ?>>
        <div class="progressive-stage-header">
          <span class="stage-pill">Step 4 · Set Password</span>
        </div>
        <div class="form-grid-2">
          <div class="field">
            <div class="field-label-row">
              <label for="password" style="margin:0">Password</label>
              <span class="label-optional">10+ chars</span>
            </div>
            <div class="password-input-wrap">
              <input id="password" name="password" type="password" autocomplete="new-password" placeholder="Create password" required>
              <button type="button" class="password-toggle-btn" data-pw-toggle="password" aria-label="Show password" aria-pressed="false">Show</button>
            </div>
            <?php if (isset($errors['password'])): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
          </div>
          <div class="field">
            <div class="field-label-row">
              <label for="password_confirm" style="margin:0">Confirm password</label>
            </div>
            <div class="password-input-wrap">
              <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" placeholder="Repeat password" required>
              <button type="button" class="password-toggle-btn" data-pw-toggle="password_confirm" aria-label="Show confirmation password" aria-pressed="false">Show</button>
            </div>
            <?php if (isset($errors['password_confirm'])): ?><div class="field-error"><?= e($errors['password_confirm']) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="pw-checklist" aria-live="polite">
          <div class="pw-rule" data-pw-rule="length">
            <span class="pw-rule-icon">•</span>
            <span>At least 10 characters long</span>
          </div>
          <div class="pw-rule" data-pw-rule="match">
            <span class="pw-rule-icon">•</span>
            <span data-pw-match-text>Both passwords must match</span>
          </div>
        </div>

        <div class="stage-actions">
          <button id="reg-submit-btn" class="btn full" type="submit" disabled>Create Account</button>
        </div>
      </div>
    </form>

    <p class="muted center" style="margin-top:1.5rem">Already have an account? <a href="/login">Sign in</a></p>
  </div>
</section>
