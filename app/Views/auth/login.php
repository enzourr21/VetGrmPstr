<?php $unlocked = !empty($emailUnlocked); ?>
<section class="auth-shell">
  <div class="auth-card">
    <div class="step-tracker step-tracker-login" aria-label="Sign-in steps">
      <div class="step-item <?= $unlocked ? 'is-done' : 'is-active' ?>" data-step-chip="1">
        <div class="step-item-bar"></div>
        <span class="step-item-label">1. Email or Username</span>
      </div>
      <div class="step-item <?= $unlocked ? 'is-active' : '' ?>" data-step-chip="2">
        <div class="step-item-bar"></div>
        <span class="step-item-label">2. Password</span>
      </div>
    </div>

    <h1>Sign in to PawCariño</h1>

    <form method="post" action="/login" data-progressive-login novalidate>
      <?= \App\Core\CSRF::field() ?>

      <!-- Step 1: Email Address or Username -->
      <div class="field">
        <div class="field-label-row">
          <label for="email" style="margin:0">Email address or username</label>
          <button type="button" class="link" data-login-edit-email <?= $unlocked ? '' : 'hidden' ?> style="padding:0;font-size:0.8rem;color:var(--brand)">Change account</button>
        </div>
        <input id="email" name="email" type="text" autocomplete="username" placeholder="name@example.com or username" required <?= $unlocked ? '' : 'autofocus' ?> value="<?= e($email ?? '') ?>">
        <div class="field-error" data-email-error <?= isset($errors['email']) ? '' : 'hidden' ?>><?= e($errors['email'] ?? '') ?></div>
      </div>

      <div class="stage-actions" data-login-continue-wrap <?= $unlocked ? 'hidden' : '' ?>>
        <button type="button" class="btn full" data-login-continue>Continue</button>
      </div>

      <!-- Step 2: Password (Hidden initially until Continue validates identifier format) -->
      <div id="login-stage-password" class="progressive-stage" data-progressive-stage="2" <?= $unlocked ? '' : 'hidden' ?>>
        <div class="progressive-stage-header">
          <span class="stage-label">Step 2 · Enter Password</span>
        </div>
        <div class="field">
          <div class="field-label-row">
            <label for="password" style="margin:0">Password</label>
          </div>
          <div class="password-input-wrap">
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" <?= $unlocked ? 'autofocus' : '' ?>>
            <button type="button" class="password-toggle-btn" data-pw-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 4.06-5.06"/><path d="M9.9 5.24A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
          </div>
        </div>
        <div class="stage-actions">
          <button class="btn full" type="submit">Sign in</button>
        </div>
      </div>
    </form>

    <p class="muted center auth-foot-link">Don't have an account yet? <a href="/register">Create an account</a></p>
  </div>
</section>
