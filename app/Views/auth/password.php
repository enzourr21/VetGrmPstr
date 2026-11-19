<section class="narrow card">
  <h1>Enter your password</h1>
  <p class="muted"><?= e($email) ?> · <a href="/login">Use a different email</a></p>
  <form method="post" action="<?= e($action) ?>">
    <?= \App\Core\CSRF::field() ?>
    <label for="password">Password</label>
    <div class="password-input-wrap">
      <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
      <button type="button" class="password-toggle-btn" data-pw-toggle="password" aria-label="Show password" aria-pressed="false"><svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.4 10.4 0 0 1 12 19c-6.5 0-10-7-10-7a18.6 18.6 0 0 1 4.06-5.06"/><path d="M9.9 5.24A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" y1="2" x2="22" y2="22"/></svg></button>
    </div>
    <p style="margin-top:1rem"><button class="btn" type="submit">Sign in</button></p>
  </form>
  <p class="muted">No account yet? <a href="/register">Create one</a></p>
</section>

