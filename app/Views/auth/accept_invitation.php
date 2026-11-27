<?php
$viewMode = (string)($mode ?? (!empty($invalid) || empty($invitation) ? 'invalid' : 'new_account'));
?>
<section class="auth-shell">
  <div class="auth-card">
    <?php if ($viewMode === 'invalid' || !empty($invalid) || empty($invitation)): ?>
      <h1>Invitation unavailable</h1>
      <p class="muted auth-lead"><?= e($errorMessage ?? 'This invitation link is invalid, expired, or has already been used.') ?></p>
      <p class="muted" style="margin-bottom: 1.25rem;">Please contact your business-unit System Administrator if you need a new invitation link.</p>
      <div class="stage-actions">
        <a class="btn full" href="/login">Go to Sign In</a>
      </div>

    <?php elseif ($viewMode === 'existing_customer_signin_required'): ?>
      <h1>Sign in to accept invitation</h1>
      <p class="muted auth-lead">An active PawCariño account already exists for <strong><?= e($invitation['email']) ?></strong>. Sign in with your existing credentials to confirm joining as an employee—your existing password and customer history will remain unchanged.</p>

      <?php if (!empty($errorMessage)): ?>
        <div class="flash flash-error" role="alert"><?= e($errorMessage) ?></div>
      <?php endif; ?>

      <div class="card" style="padding: 1rem; margin-bottom: 1.25rem; background: var(--surface-subtle, #f6f7f9);">
        <dl class="roster-meta" style="margin: 0;">
          <div><dt>Employee</dt><dd><strong><?= e($invitation['full_name']) ?></strong></dd></div>
          <div><dt>Email</dt><dd><?= e($invitation['email']) ?></dd></div>
          <div><dt>Business unit</dt><dd><?= e($invitation['business_unit_name']) ?></dd></div>
          <div><dt>Position</dt><dd><?= e($invitation['position']) ?></dd></div>
          <?php if (!empty($invitation['roles'])): ?>
            <div><dt>Assigned role(s)</dt><dd><code><?= e($invitation['roles']) ?></code></dd></div>
          <?php endif; ?>
        </dl>
      </div>

      <div class="stage-actions">
        <a class="btn full" href="/login">Sign In as <?= e($invitation['email']) ?></a>
      </div>

    <?php elseif ($viewMode === 'existing_customer_wrong_account'): ?>
      <h1>Different account signed in</h1>
      <p class="muted auth-lead">You are currently signed in as <strong><?= e((string)($signedInEmail ?? 'another account')) ?></strong>, but this invitation belongs to <strong><?= e($invitation['email']) ?></strong>.</p>

      <?php if (!empty($errorMessage)): ?>
        <div class="flash flash-error" role="alert"><?= e($errorMessage) ?></div>
      <?php endif; ?>

      <form method="post" action="/invitations/switch-account">
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="token" value="<?= e($token ?? '') ?>">
        <div class="stage-actions">
          <button class="btn full" type="submit">Sign Out &amp; Sign In with <?= e($invitation['email']) ?></button>
        </div>
      </form>

    <?php elseif ($viewMode === 'existing_customer_confirm'): ?>
      <h1>Confirm employee onboarding</h1>
      <p class="muted auth-lead">Accept this invitation to add the employee role below to your existing PawCariño account. Your existing password, email, pets, orders, and appointments will stay intact.</p>

      <?php if (!empty($errorMessage)): ?>
        <div class="flash flash-error" role="alert"><?= e($errorMessage) ?></div>
      <?php endif; ?>

      <div class="card" style="padding: 1rem; margin-bottom: 1.25rem; background: var(--surface-subtle, #f6f7f9);">
        <dl class="roster-meta" style="margin: 0;">
          <div><dt>Signed in as</dt><dd><strong><?= e((string)($signedInEmail ?? $invitation['email'])) ?></strong></dd></div>
          <div><dt>Employee name</dt><dd><?= e($invitation['full_name']) ?></dd></div>
          <div><dt>Business unit</dt><dd><?= e($invitation['business_unit_name']) ?></dd></div>
          <div><dt>Position</dt><dd><?= e($invitation['position']) ?></dd></div>
          <?php if (!empty($invitation['roles'])): ?>
            <div><dt>Assigned role(s)</dt><dd><code><?= e($invitation['roles']) ?></code></dd></div>
          <?php endif; ?>
        </dl>
      </div>

      <form method="post" action="/invitations/accept" novalidate>
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

        <div class="field">
          <label class="check-card" style="padding: 0.75rem 0.9rem;">
            <input type="checkbox" name="confirm_accept" value="1" required>
            <span>
              <strong>I confirm joining <?= e($invitation['business_unit_name']) ?> as <?= e($invitation['position']) ?></strong>
              <small>Your existing customer account and password will not be modified.</small>
            </span>
          </label>
          <?php if (isset($errors['confirm_accept'])): ?>
            <div class="field-error"><?= e($errors['confirm_accept']) ?></div>
          <?php endif; ?>
        </div>

        <div class="stage-actions">
          <button class="btn full" type="submit">Confirm &amp; Join Unit</button>
        </div>
      </form>

    <?php else: ?>
      <h1>Set up your employee account</h1>
      <p class="muted auth-lead">Create your password below to provision and activate your PawCariño employee account.</p>

      <?php if (!empty($signedInEmail)): ?>
        <div class="flash flash-info" role="status">Currently signed in as <?= e((string)$signedInEmail) ?>. Activating this new employee account will sign out that session.</div>
      <?php endif; ?>

      <?php if (!empty($errorMessage)): ?>
        <div class="flash flash-error" role="alert"><?= e($errorMessage) ?></div>
      <?php endif; ?>

      <div class="card" style="padding: 1rem; margin-bottom: 1.25rem; background: var(--surface-subtle, #f6f7f9);">
        <dl class="roster-meta" style="margin: 0;">
          <div><dt>Employee</dt><dd><strong><?= e($invitation['full_name']) ?></strong></dd></div>
          <div><dt>Email</dt><dd><?= e($invitation['email']) ?></dd></div>
          <div><dt>Business unit</dt><dd><?= e($invitation['business_unit_name']) ?></dd></div>
          <div><dt>Position</dt><dd><?= e($invitation['position']) ?></dd></div>
          <?php if (!empty($invitation['roles'])): ?>
            <div><dt>Assigned role(s)</dt><dd><code><?= e($invitation['roles']) ?></code></dd></div>
          <?php endif; ?>
        </dl>
      </div>

      <form method="post" action="/invitations/accept" novalidate>
        <?= \App\Core\CSRF::field() ?>
        <input type="hidden" name="token" value="<?= e($token ?? '') ?>">

        <div class="field">
          <div class="field-label-row">
            <label for="password" style="margin:0">New password</label>
            <span class="label-optional">At least 10 characters</span>
          </div>
          <div class="password-input-wrap">
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" required autofocus placeholder="Create a password" aria-invalid="<?= isset($errors['password']) ? 'true' : 'false' ?>">
            <button type="button" class="password-toggle-btn" data-pw-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 4.06-5.06"/><path d="M9.9 5.24A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
          </div>
          <?php if (isset($errors['password'])): ?>
            <div class="field-error"><?= e($errors['password']) ?></div>
          <?php endif; ?>
        </div>

        <div class="field">
          <div class="field-label-row">
            <label for="password_confirm" style="margin:0">Confirm password</label>
          </div>
          <div class="password-input-wrap">
            <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="10" maxlength="200" required placeholder="Repeat your password" aria-invalid="<?= isset($errors['password_confirm']) ? 'true' : 'false' ?>">
            <button type="button" class="password-toggle-btn" data-pw-toggle="password_confirm" aria-label="Show confirmation password" aria-pressed="false"><svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 4.06-5.06"/><path d="M9.9 5.24A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
          </div>
          <?php if (isset($errors['password_confirm'])): ?>
            <div class="field-error"><?= e($errors['password_confirm']) ?></div>
          <?php endif; ?>
        </div>

        <div class="stage-actions">
          <button class="btn full" type="submit">Activate Account</button>
        </div>
      </form>

      <p class="muted center auth-foot-link">Already activated? <a href="/login">Sign in</a></p>
    <?php endif; ?>
  </div>
</section>