<section class="auth-shell">
  <div class="auth-card">
    <div class="step-tracker step-tracker-login" aria-label="Staff sign-in steps">
      <div class="step-chip is-active" data-step-chip="1">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">1. Staff Email</span>
      </div>
      <div class="step-chip" data-step-chip="2">
        <div class="step-chip-bar"></div>
        <span class="step-chip-label">2. Password</span>
      </div>
    </div>

    <h1>Staff sign in</h1>
    <p class="muted" style="margin-bottom:1.35rem">Enter your staff email address and click Continue.</p>

    <form method="post" action="/staff/login" data-progressive-login novalidate>
      <?= \App\Core\CSRF::field() ?>
      <div class="field">
        <div class="field-label-row">
          <label for="email" style="margin:0">Staff email</label>
          <button type="button" class="link" data-login-edit-email hidden style="padding:0;font-size:0.8rem;color:var(--brand)">Change email</button>
        </div>
        <input id="email" name="email" type="email" autocomplete="username" placeholder="staff@vetgrpet.test" required autofocus>
        <div class="field-error" data-email-error hidden></div>
      </div>

      <div class="stage-actions" data-login-continue-wrap>
        <button type="button" class="btn full" data-login-continue>Continue</button>
      </div>

      <div id="login-stage-password" class="progressive-stage" data-progressive-stage="2" hidden>
        <div class="progressive-stage-header">
          <span class="stage-pill">Step 2 · Credentials</span>
        </div>
        <div class="field">
          <div class="field-label-row">
            <label for="password" style="margin:0">Password</label>
          </div>
          <div class="password-input-wrap">
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="button" class="password-toggle-btn" data-pw-toggle="password" aria-label="Show password" aria-pressed="false">Show</button>
          </div>
        </div>
        <div class="stage-actions">
          <button class="btn full" type="submit">Sign in</button>
        </div>
      </div>
    </form>
  </div>
</section>
