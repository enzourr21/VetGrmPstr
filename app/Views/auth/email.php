<section class="narrow card">
  <h1><?= $mode === 'login' ? 'Sign in' : 'Create your account' ?></h1>
  <form method="post" action="<?= $mode === 'login' ? '/login/email' : '/register/email' ?>" novalidate>
    <?= \App\Core\CSRF::field() ?>
    <label for="email">Email address</label>
    <input id="email" name="email" type="email" autocomplete="email" required autofocus value="<?= e($email) ?>">
    <p style="margin-top:1rem"><button class="btn" type="submit">Continue</button></p>
  </form>
  <p class="muted"><?= $mode === 'login' ? 'New here? <a href="/register">Create an account</a>' : 'Already registered? <a href="/login">Sign in</a>' ?></p>
</section>

