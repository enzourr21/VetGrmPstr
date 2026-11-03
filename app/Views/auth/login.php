<?php $unlocked = !empty($emailUnlocked); ?>
<section class="auth-shell">
  <div class="auth-card">
    <div class="step-tracker step-tracker-login" aria-label="Sign-in steps">
      <div class="step-chip <?= $unlocked ? 'is-done' : 'is-active' ?>" data-step-chip="1">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">1. Account Email</span>
      </div>
      <div class="step-chip <?= $unlocked ? 'is-active' : '' ?>" data-step-chip="2">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">2. Password</span>
      </div>
    </div>

    <h1>Sign in to VETGRPET</h1>
    <p class="muted" style="margin-bottom:1.35rem">Enter your email address and click Continue to proceed to your password.</p>

    <form method="post" action="/login" data-progressive-login novalidate>
      <?= \App\Core\CSRF::field() ?>

      <!-- Step 1: Email Address -->
      <div class="field">
        <div class="field-label-row">
          <label for="email" style="margin:0">Email address</label>
          <button type="button" class="link" data-login-edit-email <?= $unlocked ? '' : 'hidden' ?> style="padding:0;font-size:0.8rem;color:var(--brand)">Change email</button>
        </div>
        <input id="email" name="email" type="email" autocomplete="email" placeholder="name@example.com" required <?= $unlocked ? '' : 'autofocus' ?> value="<?= e($email ?? '') ?>">
        <div class="field-error" data-email-error <?= isset($errors['email']) ? '' : 'hidden' ?>><?= e($errors['email'] ?? '') ?></div>
      </div>

      <div class="stage-actions" data-login-continue-wrap <?= $unlocked ? 'hidden' : '' ?>>
        <button type="button" class="btn full" data-login-continue>Continue</button>
      </div>

      <!-- Step 2: Password (Hidden initially until Continue validates email format) -->
      <div id="login-stage-password" class="progressive-stage" data-progressive-stage="2" <?= $unlocked ? '' : 'hidden' ?>>
        <div class="progressive-stage-header">
          <span class="stage-pill">Step 2 · Enter Password</span>
        </div>
        <div class="field">
          <div class="field-label-row">
            <label for="password" style="margin:0">Password</label>
          </div>
          <div class="password-input-wrap">
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" <?= $unlocked ? 'autofocus' : '' ?>>
            <button type="button" class="password-toggle-btn" data-pw-toggle="password" aria-label="Show password" aria-pressed="false">Show</button>
          </div>
        </div>
        <div class="stage-actions">
          <button class="btn full" type="submit">Sign in</button>
        </div>
      </div>
    </form>

    <p class="muted center" style="margin-top:1.5rem">Don't have an account yet? <a href="/register">Create an account</a></p>
  </div>
</section>
