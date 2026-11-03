<?php
$curPath = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';
$isCustomer = (bool)\App\Core\Session::get('customer_id');
$isEmployee = (bool)\App\Core\Session::get('employee_id');
$isSignedIn = (bool)\App\Core\Session::get('user_id');
$isAuthSignInOrOut = str_starts_with($curPath, '/login') || str_starts_with($curPath, '/staff/login') || str_starts_with($curPath, '/logout');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · VETGRPET</title>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= file_exists(APP_ROOT . '/public/assets/css/app.css') ? filemtime(APP_ROOT . '/public/assets/css/app.css') : time() ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/">
      <span class="brand-mark" aria-hidden="true">VP</span>
      <span>VETGRPET</span>
    </a>
    <nav aria-label="Main">
      <a href="/products" class="<?= (str_starts_with($curPath, '/products') || str_starts_with($curPath, '/shop')) ? 'is-current' : '' ?>">Products</a>
      <a href="/services" class="<?= str_starts_with($curPath, '/services') ? 'is-current' : '' ?>">Services</a>
      <a href="/staffs" class="<?= str_starts_with($curPath, '/staffs') ? 'is-current' : '' ?>">Staffs</a>
      <a href="/reviews" class="<?= str_starts_with($curPath, '/reviews') ? 'is-current' : '' ?>">Customer Reviews</a>
<?php if ($isCustomer): ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/appointments" class="<?= str_starts_with($curPath, '/appointments') ? 'is-current' : '' ?>">Appointments</a>
      <a href="/pets" class="<?= str_starts_with($curPath, '/pets') ? 'is-current' : '' ?>">My pets</a>
      <a href="/orders" class="<?= str_starts_with($curPath, '/orders') ? 'is-current' : '' ?>">Orders</a>
      <a href="/profile" class="<?= str_starts_with($curPath, '/profile') ? 'is-current' : '' ?>">Profile</a>
<?php endif; ?>
<?php if ($isEmployee): ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/staff/appointments" class="<?= str_starts_with($curPath, '/staff') ? 'is-current' : '' ?>">Workspace</a>
<?php endif; ?>
<?php if ($isSignedIn): ?>
      <form method="post" action="/logout" class="inline"><?= \App\Core\CSRF::field() ?><button class="link" type="submit">Sign out</button></form>
<?php else: ?>
      <span class="nav-divider" aria-hidden="true"></span>
      <a href="/login" class="<?= str_starts_with($curPath, '/login') ? 'is-current' : '' ?>">Sign in</a>
      <a class="nav-cta" href="/register">Create account</a>
<?php endif; ?>
    </nav>
  </div>
</header>
<?php if (!$isAuthSignInOrOut): ?>
  <?php require APP_ROOT . '/app/Views/partials/scenic_rails.php'; ?>
<?php endif; ?>
<main id="main">
<?php foreach ($flashes as [$type, $msg]): ?>
  <div class="flash flash-<?= e($type) ?>" role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($msg) ?></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="foot">VETGRPET · Pet Store · Veterinary Clinic · Grooming Services · Sample data marked [SAMPLE]</footer>
<script src="/assets/js/app.js?v=<?= file_exists(APP_ROOT . '/public/assets/js/app.js') ? filemtime(APP_ROOT . '/public/assets/js/app.js') : time() ?>" defer></script>
</body>
</html>
