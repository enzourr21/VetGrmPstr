<section class="narrow card">
  <h1>Enter your password</h1>
  <p class="muted"><?= e($email) ?> · <a href="/login">Use a different email</a></p>
  <form method="post" action="<?= e($action) ?>">
    <?= \App\Core\CSRF::field() ?>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
    <p style="margin-top:1rem"><button class="btn" type="submit">Sign in</button></p>
  </form>
  <p class="muted">No account yet? <a href="/register">Create one</a></p>
</section>

